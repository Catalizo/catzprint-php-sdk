<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Client;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConflictException;
use CatzPrint\Exception\ConnectionException;
use CatzPrint\Exception\OutcomeUnknownException;
use CatzPrint\Exception\ServerException;
use CatzPrint\Exception\StationUnreachableException;
use CatzPrint\Exception\TimeoutException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\CloudJob;
use CatzPrint\Internal\HttpClient;
use CatzPrint\Internal\Local\LocalState;
use CatzPrint\Internal\RetryPolicy;
use CatzPrint\Internal\Sealer;
use CatzPrint\Internal\Uuid;
use CatzPrint\Local\Mode;
use CatzPrint\Model\Job;
use CatzPrint\Model\JobPage;

/**
 * Print jobs: create, read, list, cancel, retry, reprint and wait.
 */
final class Jobs
{
    /** create() params => API field names (only these are accepted). */
    private const CREATE_FIELDS = [
        'printerId' => 'printer_id',
        'locationId' => 'location_id',
        'role' => 'role',
        'kind' => 'kind',
        'title' => 'title',
        'reference' => 'reference',
        'copies' => 'copies',
        'idempotencyKey' => 'idempotency_key',
        'scheduledFor' => 'scheduled_for',
        'options' => 'options',
        'fileName' => 'file_name',
        // content sources
        'text' => null,
        'commands' => 'commands',
        'templateId' => 'template_id',
        'data' => 'data',
        'content' => null,
        'sealed' => null,
    ];

    /** list() params => query names. */
    private const LIST_FIELDS = [
        'status' => 'status',
        'printerId' => 'printer_id',
        'stationId' => 'station_id',
        'locationId' => 'location_id',
        'source' => 'source',
        'live' => 'live',
        'q' => 'q',
        'since' => 'since',
        'limit' => 'limit',
        'cursor' => 'cursor',
        'counts' => 'counts',
    ];

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Creates a print job.
     *
     * Choose the printer with printerId, or let routing pick one with role
     * and/or locationId. Give exactly one content source:
     *  - text: string                       (kind "text")
     *  - commands: list of receipt commands (kind "commands")
     *  - templateId + data                  (kind "template")
     *  - content: raw bytes (PDF, PNG/JPEG, ESC/POS, Office file) with an
     *    optional fileName; kind is detected for PDF and images
     * Add sealed: true to encrypt text/content to the server key before it
     * leaves this machine (needs ext-sodium).
     *
     * An idempotencyKey is always sent (a UUIDv4 is generated when you don't
     * give one), so the request is safely retried and can never print twice.
     *
     * With the "local" client option in a mode other than cloud, the job goes
     * to a station on the local network and/or the cloud as the mode says;
     * $job->via tells which one took it. In the local modes the same
     * idempotency key ("sdk-<uuid v4>" when you don't give one) goes to the
     * station and to the cloud, so a fallback never prints twice. PDFs,
     * images, documents, sealed and scheduled jobs can't print locally: they
     * go to the cloud in local_first/cloud_first and throw a
     * ValidationException "unsupported_locally" in local mode.
     *
     * @param array{
     *   printerId?: string, locationId?: string, role?: string, kind?: string,
     *   title?: string, reference?: string, copies?: int, idempotencyKey?: string,
     *   scheduledFor?: \DateTimeInterface|string, options?: array<string, mixed>,
     *   text?: string, commands?: list<array<string, mixed>>, templateId?: string,
     *   data?: array<string, mixed>, content?: string, fileName?: string, sealed?: bool
     * } $params
     */
    public function create(array $params): Job
    {
        $this->checkCreateParams($params);
        $params = self::dropBlankKey($params);
        $local = $this->client->localState();
        $mode = $local->cfg->mode;
        if ($mode === Mode::CLOUD) {
            if (!isset($params['idempotencyKey'])) {
                // Fixed here so a 409 re-send repeats the identical request.
                $params['idempotencyKey'] = Uuid::v4();
            }

            return $this->createCloudMode($this->prepareCloud($params));
        }

        // The same key goes to the station and to the cloud, so a fallback
        // can never print twice.
        if (!isset($params['idempotencyKey'])) {
            $params['idempotencyKey'] = 'sdk-' . Uuid::v4();
        }
        if (empty($params['printerId']) && empty($params['locationId']) && $local->cfg->locationId !== '') {
            $params['locationId'] = $local->cfg->locationId;
        }
        if (isset($params['content']) && is_string($params['content']) && empty($params['kind'])
            && LocalState::detectKind($params['content'], (string) ($params['fileName'] ?? '')) === 'escpos') {
            $params['kind'] = 'escpos';
        }
        $why = self::whyNotLocal($params);

        switch ($mode) {
            case Mode::LOCAL:
                if ($why !== null) {
                    throw self::unsupportedLocally($why);
                }

                return $this->createLocal($params);
            case Mode::LOCAL_FIRST:
                if ($why !== null) {
                    return $this->createCloudMode($this->prepareCloud($params));
                }
                try {
                    return $this->createLocal($params);
                } catch (StationUnreachableException $sue) {
                    // One attempt at a time (no automatic retries), so an
                    // ambiguous answer is handled by cloudLeg().
                    $http = $this->client->http();
                    [$job, $cloudError] = $this->cloudLeg($this->prepareCloud($params), $http->with($http->timeout(), new RetryPolicy(0)));
                    if ($job !== null) {
                        return $job;
                    }

                    throw $sue->withCloudError($cloudError ?? new \LogicException('no cloud error'));
                }
                // no break
            default: // cloud_first: one short cloud attempt, then the stations
                if ($why !== null) {
                    // Can't print locally: the same create as cloud mode (client retries, 409 loop).
                    return $this->createCloudMode($this->prepareCloud($params));
                }
                [$job, $cloudError] = $this->cloudLeg($this->prepareCloud($params), $local->cloudHttp());
                if ($job !== null) {
                    return $job;
                }
                if ($why !== null || $cloudError === null) {
                    throw $cloudError ?? new \LogicException('no cloud error');
                }
                try {
                    return $this->createLocal($params);
                } catch (StationUnreachableException $sue) {
                    throw $sue->withCloudError($cloudError);
                }
        }
    }

    /**
     * The cloud leg of local_first / cloud_first (addendum 2, SDK-1):
     *  - answered: the job, or its 4xx error is thrown;
     *  - not delivered, 502, 503 or 504: [null, error] (the caller may go local);
     *  - delivered without an answer, or another 5xx: the create is retried
     *    ONCE with the same key (the cloud dedupes on it); when that doesn't
     *    answer either, OutcomeUnknownException (never local: the cloud may
     *    already have queued the job for another station).
     *
     * @return array{0: Job|null, 1: \Throwable|null}
     */
    private function cloudLeg(CloudJob $cj, HttpClient $http): array
    {
        [$job, $kind, $err] = $this->tryCloud($cj, $http);
        if ($kind === 'ok' || $kind === 'not_delivered') {
            return [$job, $err];
        }
        if ($kind === 'ambiguous') {
            $cj->ambiguous = true;
            [$job, $kind, $err2] = $this->tryCloud($cj, $http);
            if ($job !== null) {
                return [$job, null];
            }
            if ($kind !== 'duplicate') {
                throw new OutcomeUnknownException($http->baseUrl(), $cj->idempotencyKey(), $err2 ?? $err);
            }
            $err = $err2;
        }

        return [$this->resendWhileDuplicate($cj, $http, $err), null];
    }

    /** Re-sends after a 409 "duplicate" (the job is being created): at most this often. */
    public const DUPLICATE_RESENDS = 3;

    /**
     * After a 409 "duplicate" (addendum 3, R4): waits 1 s and re-sends the
     * identical request, at most 3 times. A job -> that job. A re-send that
     * isn't delivered or is ambiguous -> OutcomeUnknownException (never
     * local). Still 409 after the last re-send -> that ConflictException (the
     * cloud's answer: the key belongs to a job being created or one this key
     * can't see).
     */
    private function resendWhileDuplicate(CloudJob $cj, HttpClient $http, ?\Throwable $conflict): Job
    {
        $cj->ambiguous = true; // a job with this key exists or is being created
        for ($i = 0; $i < self::DUPLICATE_RESENDS; $i++) {
            $http->sleep(1.0);
            [$job, $kind, $err] = $this->tryCloud($cj, $http);
            if ($job !== null) {
                return $job;
            }
            if ($kind !== 'duplicate') {
                throw new OutcomeUnknownException($http->baseUrl(), $cj->idempotencyKey(), $err);
            }
            $conflict = $err;
        }

        throw $conflict ?? new \LogicException('no conflict');
    }

    /**
     * One cloud create, classified: ok, not_delivered (nothing was POSTed,
     * or 502/503/504), ambiguous (POSTed then no answer, another 5xx, or a
     * 2xx without a readable job) or duplicate (409 "duplicate"). Other
     * answers (4xx) are thrown.
     *
     * @return array{0: Job|null, 1: string, 2: \Throwable|null}
     */
    private function tryCloud(CloudJob $cj, HttpClient $http): array
    {
        $posting = false;
        try {
            $job = $this->createCloud($cj, $http, $posting);
            if ($job->id === '') {
                return [null, 'ambiguous', new CatzPrintException('The API answered without a job.', 0, 'invalid_response')];
            }

            return [$job, 'ok', null];
        } catch (ConflictException $e) {
            if ($e->errorCode === 'duplicate') {
                return [null, 'duplicate', $e];
            }

            throw $e;
        } catch (ConnectionException $e) {
            // A failed org-ID or server-key lookup means the job POST was never sent.
            return [null, $posting && $e->requestDelivered() ? 'ambiguous' : 'not_delivered', $e];
        } catch (ServerException $e) {
            return [null, !$posting || in_array($e->status, [502, 503, 504], true) ? 'not_delivered' : 'ambiguous', $e];
        } catch (CatzPrintException $e) {
            if ($e->errorCode === 'response_too_large' || $e->errorCode === 'invalid_response'
                || ($e->status >= 300 && $e->status < 400)) {
                // Unreadable, too large, or a redirect (never followed) after the POST: ambiguous.
                return [null, $posting ? 'ambiguous' : 'not_delivered', $e];
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $params */
    private function createLocal(array $params): Job
    {
        [$body] = $this->buildBody($params);
        unset($body['file_name']);

        return $this->client->localState()->createJob($body, $params);
    }

    /**
     * Why a job can't go to a station (null when it can).
     *
     * @param array<string, mixed> $params
     */
    private static function whyNotLocal(array $params): ?string
    {
        if (!empty($params['sealed'])) {
            return 'sealed jobs go through the cloud';
        }
        if (isset($params['scheduledFor'])) {
            return 'scheduled jobs go through the cloud';
        }
        if (isset($params['content'])) {
            $k = LocalState::inferKind($params);
            if ($k !== 'escpos' && $k !== 'text') {
                return 'PDFs, images and documents go through the cloud';
            }
        }

        return in_array((string) ($params['kind'] ?? ''), ['', 'commands', 'template', 'text', 'escpos', 'test'], true)
            ? null
            : 'PDFs, images and documents go through the cloud';
    }

    private static function unsupportedLocally(string $why): ValidationException
    {
        return new ValidationException(
            'This job can\'t print locally: ' . $why . '. Send PDFs, images and documents through the cloud.',
            422,
            'unsupported_locally',
        );
    }

    /** @param array<string, mixed> $params */
    private function checkCreateParams(array $params): void
    {
        $unknown = array_diff(array_keys($params), array_keys(self::CREATE_FIELDS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown job parameter(s): ' . implode(', ', $unknown)
                . '. Use: ' . implode(', ', array_keys(self::CREATE_FIELDS)) . '.');
        }

        $sources = array_values(array_filter(
            ['text', 'commands', 'templateId', 'content'],
            static fn (string $k): bool => array_key_exists($k, $params) && $params[$k] !== null,
        ));
        if (count($sources) > 1) {
            throw new \InvalidArgumentException('Give only one of text, commands, templateId or content (got ' . implode(', ', $sources) . ').');
        }
        if (isset($params['data']) && !isset($params['templateId'])) {
            throw new \InvalidArgumentException('"data" fills a template; also pass "templateId".');
        }
        self::checkIdempotencyKey($params['idempotencyKey'] ?? null);
    }

    /**
     * An empty (or all-whitespace) key counts as "no key"; a key with
     * leading/trailing whitespace or control characters is refused rather
     * than silently changed (addendum 2, SDK-2).
     */
    /** Unicode whitespace: \s, separators (\p{Z}), U+0085 (NEL), U+FEFF (BOM). */
    private const SPACE = '[\s\p{Z}\x{0085}\x{FEFF}]';

    private static function isBlankKey(mixed $key): bool
    {
        return is_string($key) && ($key === '' || preg_match('/^' . self::SPACE . '*$/u', $key) === 1);
    }

    private static function checkIdempotencyKey(mixed $key): void
    {
        if ($key === null || self::isBlankKey($key)) {
            return;
        }
        $msg = null;
        if (!is_string($key)) {
            $msg = '"idempotencyKey" must be a string.';
        } elseif (preg_match('//u', $key) !== 1) {
            $msg = 'The idempotency key is not valid UTF-8.';
        } elseif (preg_match('/^' . self::SPACE . '|' . self::SPACE . '$/u', $key) === 1) {
            $msg = 'The idempotency key has leading or trailing whitespace.';
        } elseif (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $key) === 1) {
            $msg = 'The idempotency key contains control characters.';
        } elseif (strlen($key) > 200) {
            $msg = 'The idempotency key is longer than 200 characters.';
        }
        if ($msg !== null) {
            throw new ValidationException($msg, 0, 'invalid', ['idempotency_key' => $msg]);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> $params without a blank idempotencyKey
     */
    private static function dropBlankKey(array $params): array
    {
        if (isset($params['idempotencyKey']) && self::isBlankKey($params['idempotencyKey'])) {
            unset($params['idempotencyKey']);
        }

        return $params;
    }

    /**
     * The jobs.create JSON body (without sealing) and the raw bytes to seal.
     *
     * @param array<string, mixed> $params
     *
     * @return array{0: array<string, mixed>, 1: string|null, 2: bool}
     */
    private function buildBody(array $params): array
    {
        $body = [];
        foreach (self::CREATE_FIELDS as $param => $field) {
            if ($field === null || !array_key_exists($param, $params) || $params[$param] === null) {
                continue;
            }
            $body[$field] = $params[$param];
        }
        if (isset($body['scheduled_for']) && $body['scheduled_for'] instanceof \DateTimeInterface) {
            $body['scheduled_for'] = \DateTimeImmutable::createFromInterface($body['scheduled_for'])
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        foreach (['options', 'data'] as $map) {
            if (isset($body[$map])) {
                if (!is_array($body[$map])) {
                    throw new \InvalidArgumentException("\"$map\" must be an array (JSON object).");
                }
                if ($body[$map] === []) {
                    $body[$map] = new \stdClass();
                }
            }
        }
        if (isset($body['commands']) && (!is_array($body['commands']) || !array_is_list($body['commands']))) {
            throw new \InvalidArgumentException('"commands" must be a list of command arrays.');
        }

        // The bytes for content / sealed_content.
        $bytes = null;
        if (isset($params['text'])) {
            if (!is_string($params['text'])) {
                throw new \InvalidArgumentException('"text" must be a string.');
            }
            $body['kind'] ??= 'text';
            $bytes = $params['text'];
        } elseif (isset($params['content'])) {
            if (!is_string($params['content'])) {
                throw new \InvalidArgumentException('"content" must be a string of bytes.');
            }
            $bytes = $params['content'];
            $body['kind'] ??= self::detectKind($bytes);
        }

        $sealed = (bool) ($params['sealed'] ?? false);
        if ($sealed && $bytes === null) {
            throw new \InvalidArgumentException('"sealed" needs text or content; commands and template data are JSON and can\'t be sealed.');
        }
        if ($bytes !== null && !$sealed) {
            $body['content'] = ($body['kind'] ?? '') === 'text' ? $bytes : base64_encode($bytes);
        }

        $key = (string) ($body['idempotency_key'] ?? '');
        $body['idempotency_key'] = $key !== '' ? $key : Uuid::v4();

        return [$body, $bytes, $sealed];
    }

    /** @param array<string, mixed> $params */
    /**
     * Builds the cloud create body once (sealing happens on the first attempt).
     *
     * @param array<string, mixed> $params
     */
    private function prepareCloud(array $params): CloudJob
    {
        [$body, $bytes, $sealed] = $this->buildBody($params);

        return new CloudJob($body, $bytes, $sealed);
    }

    /**
     * One POST of the prepared job, sending the same bytes every time.
     *
     * @param bool $posting set to true while the job POST itself is in flight
     *                      (a failure before it, e.g. the org-ID or server-key lookup, means not delivered)
     */
    private function createCloud(CloudJob $cj, HttpClient $http, bool &$posting = false): Job
    {
        $posting = false;
        $cj->path ??= $this->client->orgPath('/jobs');
        if ($cj->payload === null) {
            $body = $cj->body;
            if ($cj->sealed) {
                $body['sealed_content'] = Sealer::seal((string) $cj->bytes, $this->client->serverKey());
            }
            $cj->payload = HttpClient::encode($body);
        }
        $onAmbiguous = static function () use ($cj): void {
            $cj->ambiguous = true;
        };
        try {
            $posting = true;

            return $this->jobFrom($http->sendEncoded('POST', $cj->path, $cj->payload, true, $onAmbiguous), Job::VIA_CLOUD);
        } catch (ValidationException $e) {
            if (!$cj->sealed || !isset($e->fields['sealed_content'])) {
                throw $e;
            }
            if ($cj->resealed) {
                // Rejected again after the re-seal: after an ambiguous attempt
                // the job may exist, so this isn't the outcome (S1).
                throw $cj->ambiguous ? new OutcomeUnknownException($http->baseUrl(), $cj->idempotencyKey(), $e) : $e;
            }
            // The server key was rotated (the POST was answered): fetch it
            // again and re-seal once; later re-sends reuse these bytes.
            $posting = false;
            $cj->resealed = true;
            try {
                $key = $this->client->serverKey(true);
            } catch (\Throwable $ke) {
                if ($cj->ambiguous) {
                    throw new OutcomeUnknownException($http->baseUrl(), $cj->idempotencyKey(), $ke);
                }

                throw $e; // nothing more was sent: the API's answer stands
            }
            $body = $cj->body;
            $body['sealed_content'] = Sealer::seal((string) $cj->bytes, $key);
            $cj->payload = HttpClient::encode($body);
            $posting = true;
            try {
                return $this->jobFrom($http->sendEncoded('POST', $cj->path, $cj->payload, true, $onAmbiguous), Job::VIA_CLOUD);
            } catch (CatzPrintException $re) {
                if ($re instanceof OutcomeUnknownException || ($re instanceof ConflictException && $re->errorCode === 'duplicate')) {
                    throw $re; // a 409 "duplicate" goes to the callers' re-send loop
                }
                $maybeArrived = ($re instanceof ConnectionException && $re->requestDelivered())
                    || ($re instanceof ServerException && !in_array($re->status, [502, 503, 504], true))
                    || ($re->status >= 300 && $re->status < 400)
                    || $re->errorCode === 'invalid_response' || $re->errorCode === 'response_too_large';
                if ($cj->ambiguous || $maybeArrived) {
                    // After an ambiguous attempt only the job (or a 409) settles
                    // it; a re-send that may have arrived is no answer either.
                    throw new OutcomeUnknownException($http->baseUrl(), $cj->idempotencyKey(), $re);
                }

                throw $re;
            }
        }
    }

    /**
     * Cloud mode: one create (with the client's retries) plus the 409
     * "duplicate" re-sends of addendum 3, R4.
     */
    private function createCloudMode(CloudJob $cj): Job
    {
        $http = $this->client->http();
        try {
            return $this->createCloud($cj, $http);
        } catch (ConflictException $e) {
            if ($e->errorCode !== 'duplicate') {
                throw $e;
            }

            return $this->resendWhileDuplicate($cj, $http, $e);
        }
    }

    /**
     * One job, with its status history in $job->events. Local job IDs
     * ("lj_…", from a job a station took) are read from the station.
     */
    public function get(string $id): Job
    {
        if (str_starts_with($id, 'lj_')) {
            return $this->client->local->getJob($id);
        }
        $res = $this->client->http()->request('GET', $this->client->orgPath('/jobs/' . rawurlencode($id)));
        $events = isset($res['events']) && is_array($res['events']) ? array_values($res['events']) : [];

        return new Job(self::jobArray($res), $events, Job::VIA_CLOUD);
    }

    /**
     * One page of jobs, newest first.
     *
     * @param array{
     *   status?: string|list<string>, printerId?: string, stationId?: string, locationId?: string,
     *   source?: string, live?: bool, q?: string, since?: \DateTimeInterface|string,
     *   limit?: int, cursor?: string, counts?: bool
     * } $params
     */
    public function list(array $params = []): JobPage
    {
        $unknown = array_diff(array_keys($params), array_keys(self::LIST_FIELDS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown list parameter(s): ' . implode(', ', $unknown) . '.');
        }
        $query = [];
        foreach (self::LIST_FIELDS as $param => $name) {
            if (!isset($params[$param])) {
                continue;
            }
            $v = $params[$param];
            if (is_array($v)) {
                $v = implode(',', $v);
            } elseif ($v instanceof \DateTimeInterface) {
                $v = \DateTimeImmutable::createFromInterface($v)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            }
            $query[$name] = $v;
        }
        $res = $this->client->http()->request('GET', $this->client->orgPath('/jobs'), $query);
        $items = [];
        foreach (is_array($res['items'] ?? null) ? $res['items'] : [] as $item) {
            if (is_array($item)) {
                $items[] = new Job($item);
            }
        }
        $counts = [];
        foreach (is_array($res['counts'] ?? null) ? $res['counts'] : [] as $k => $n) {
            $counts[(string) $k] = (int) $n;
        }
        $next = isset($res['next_cursor']) && is_string($res['next_cursor']) && $res['next_cursor'] !== '' ? $res['next_cursor'] : null;
        unset($params['cursor']);

        return new JobPage($items, $next, $counts, $this, $params);
    }

    /**
     * Every job matching $params, fetching further pages as you iterate.
     *
     * @param array<string, mixed> $params Same as list().
     *
     * @return \Generator<int, Job>
     */
    public function all(array $params = []): \Generator
    {
        /** @phpstan-ignore-next-line */
        return $this->list($params)->autoPagingIterator();
    }

    /** Cancels a scheduled, held, queued or sent job. */
    public function cancel(string $id): Job
    {
        return $this->jobFrom($this->client->http()->request('POST', $this->client->orgPath('/jobs/' . rawurlencode($id) . '/cancel'), [], []));
    }

    /** Queues a failed or held job again (operator keys). */
    public function retry(string $id): Job
    {
        return $this->jobFrom($this->client->http()->request('POST', $this->client->orgPath('/jobs/' . rawurlencode($id) . '/retry'), [], []));
    }

    /**
     * Prints a job's content again as a new job.
     *
     * @param array{printerId?: string, copies?: int} $params
     */
    public function reprint(string $id, array $params = []): Job
    {
        $unknown = array_diff(array_keys($params), ['printerId', 'copies']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown reprint parameter(s): ' . implode(', ', $unknown) . '.');
        }
        $body = [];
        if (isset($params['printerId'])) {
            $body['printer_id'] = $params['printerId'];
        }
        if (isset($params['copies'])) {
            $body['copies'] = $params['copies'];
        }

        return $this->jobFrom($this->client->http()->request('POST', $this->client->orgPath('/jobs/' . rawurlencode($id) . '/reprint'), [], $body));
    }

    /**
     * Polls until the job is printed, failed or cancelled and returns it.
     *
     * @param float $timeout  Seconds to wait in total (default 60).
     * @param float $interval Seconds between polls (default 1).
     *
     * @throws TimeoutException when the job is still not final after $timeout.
     */
    public function waitFor(string $id, float $timeout = 60.0, float $interval = 1.0): Job
    {
        if (!($interval > 0)) {
            $interval = 1.0; // never poll in a tight loop
        }
        $deadline = microtime(true) + $timeout;
        while (true) {
            $job = $this->get($id);
            if ($job->isFinal()) {
                return $job;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new TimeoutException(
                    sprintf('Job %s is still "%s" after %.1f s.', $id, $job->status, $timeout),
                    0,
                    'timeout',
                );
            }
            $this->client->http()->sleep(min($interval, $left));
        }
    }

    /** Best-effort kind for raw content: pdf, image, or document (server-detected). */
    public static function detectKind(string $bytes): string
    {
        if (str_starts_with($bytes, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($bytes, "\x89PNG") || str_starts_with($bytes, "\xff\xd8\xff")) {
            return 'image';
        }

        return 'document';
    }

    /** @param array<string, mixed> $res */
    private function jobFrom(array $res, ?string $via = null): Job
    {
        return new Job(self::jobArray($res), null, $via);
    }

    /**
     * @param array<string, mixed> $res
     *
     * @return array<string, mixed>
     */
    private static function jobArray(array $res): array
    {
        return isset($res['job']) && is_array($res['job']) ? $res['job'] : $res;
    }
}
