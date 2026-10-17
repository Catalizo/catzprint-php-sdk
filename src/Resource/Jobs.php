<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Client;
use CatzPrint\Exception\TimeoutException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\Sealer;
use CatzPrint\Internal\Uuid;
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

        $key = trim((string) ($body['idempotency_key'] ?? ''));
        $body['idempotency_key'] = $key !== '' ? $key : Uuid::v4();

        $path = $this->client->orgPath('/jobs');
        if (!$sealed) {
            return $this->jobFrom($this->client->http()->request('POST', $path, [], $body));
        }

        $body['sealed_content'] = Sealer::seal((string) $bytes, $this->client->serverKey());
        try {
            return $this->jobFrom($this->client->http()->request('POST', $path, [], $body));
        } catch (ValidationException $e) {
            if (!isset($e->fields['sealed_content'])) {
                throw $e;
            }
            // The server key was rotated: fetch it again and re-seal once.
            $body['sealed_content'] = Sealer::seal((string) $bytes, $this->client->serverKey(true));

            return $this->jobFrom($this->client->http()->request('POST', $path, [], $body));
        }
    }

    /** One job, with its status history in $job->events. */
    public function get(string $id): Job
    {
        $res = $this->client->http()->request('GET', $this->client->orgPath('/jobs/' . rawurlencode($id)));
        $events = isset($res['events']) && is_array($res['events']) ? array_values($res['events']) : [];

        return new Job(self::jobArray($res), $events);
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
    private function jobFrom(array $res): Job
    {
        return new Job(self::jobArray($res));
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
