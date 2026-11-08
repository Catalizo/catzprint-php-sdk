# CatzPrint PHP SDK

The official PHP client for the [CatzPrint](https://catzprint.com) printing API. Print receipts, PDFs, images and templates on
your printers, keep printing through a station on the local network when the internet is down, seal documents end to end,
follow jobs until they print, and verify webhooks.

- PHP 8.1+, **no Composer dependencies** — just `ext-curl`, `ext-json` and `ext-openssl` (`ext-sodium`, bundled with PHP,
  for sealed printing; optional `ext-sockets` for mDNS in local printing)
- Typed models, one exception per error kind, safe automatic retries
- MIT licensed

## Install

```bash
composer require catzprint/catzprint-php
```

## Quickstart

```php
<?php
require 'vendor/autoload.php';

$catz = new CatzPrint\Client('cz_test_…');            // or set CATZPRINT_API_KEY

$job = $catz->printText("Table 7\n2 x Eggs on toast\n", ['role' => 'kitchen']);
$job = $catz->jobs->waitFor($job->id);                // polls until printed / failed / cancelled

echo "{$job->id} is {$job->status} on {$job->printerName}\n";
```

## Configuration

```php
$catz = new CatzPrint\Client('cz_live_…', [
    'baseUrl'    => 'https://api-v1.catzprint.com', // or env CATZPRINT_BASE_URL
    'orgId'      => '34c4671b-…',                   // optional; otherwise looked up once with GET /v1/key
    'timeout'    => 30,                             // seconds per request
    'maxRetries' => 2,                              // retries on network errors, 429 and 502/503/504
]);
```

Every API key belongs to one organization. The client finds it on first use (`GET /v1/key`) and caches it.
The key is never logged: `var_dump($catz)` / `print_r($catz)` show `cz_live_…a1b2`, and the client refuses to be serialized.

## Reference

### Who am I

```php
$key = $catz->key();          // or $catz->whoami()
$key->orgId;                  // "34c4671b-…"
$key->orgName;                // "Corner Cafe"
$key->live;                   // false for cz_test_ keys
$key->scopes;                 // ["jobs:write", "jobs:read", "printers:read", "locations:read"]
$key->hasScope('jobs:write'); // true
```

### Printing

Pick the printer with `printerId`, or let CatzPrint route the job with `role` (`receipt`, `kitchen`, `bar`, `label`, `office`,
`other`) and optionally `locationId`. Every helper accepts any `jobs->create()` option.

Printer names aren't unique (two stations can each have a "Kitchen"), so target printers by ID. `$catz->printers->list()` returns each printer's `id`, `station_id`, `station_name`, `location_id` and `role`. Optional shortcut: in the CatzPrint console, open **Locations & stations** (**My devices** in a personal account), then a station, and click the ID under a printer's name to copy it.

```php
// Plain text
$catz->printText("Hello!\n", ['printerId' => $printerId, 'title' => 'Greeting']);

// A PDF — bytes or a file path
$catz->printPdf('/path/invoice-2041.pdf', ['role' => 'office', 'copies' => 2, 'reference' => 'INV-2041']);
$catz->printPdf($pdfBytes, ['printerId' => $officePrinterId]);

// A PNG or JPEG — dithered on receipt printers
$catz->printImage('/path/logo.png', ['role' => 'receipt']);

// A receipt from commands (text, columns, line, barcode, qr, image, feed, cut, drawer, beep)
$catz->printReceipt([
    ['type' => 'text', 'text' => 'CORNER CAFE', 'align' => 'center', 'size' => 2, 'bold' => true],
    ['type' => 'line', 'char' => '='],
    ['type' => 'columns', 'columns' => [['text' => '2 x Flat white', 'width' => 0.7], ['text' => '9.00', 'align' => 'right']]],
    ['type' => 'qr', 'data' => 'https://cornercafe.example/r/A-1042', 'align' => 'center'],
    ['type' => 'cut', 'partial' => true],
], ['role' => 'receipt', 'reference' => 'A-1042']);

// A saved template with {{placeholders}}
$catz->printTemplate($templateId, ['order' => ['number' => 'A-1042', 'table' => 7, 'items' => [/* … */]]], [
    'role' => 'kitchen',
    'locationId' => $locationId,
]);
```

### `jobs->create()`

The general form behind the helpers. Give exactly **one** content source.

| Option | Type | |
|---|---|---|
| `printerId` / `locationId` / `role` | string | Where to print (see above) |
| `text` | string | Plain text (kind `text`) |
| `commands` | list | Receipt commands (kind `commands`) |
| `templateId` + `data` | string + array | Template and its values (kind `template`) |
| `content` | string (bytes) | PDF, PNG/JPEG, raw ESC/POS or an Office file. Kind is detected for PDF and images |
| `fileName` | string | Original name, used to recognise Office files (`.docx`, `.xlsx`…) |
| `kind` | string | `commands`, `template`, `text`, `escpos`, `image`, `pdf`, `document` — usually inferred |
| `sealed` | bool | Encrypt `text`/`content` to the server key before sending |
| `title` | string | Up to 200 characters |
| `reference` | string | Your own reference, up to 120 characters (searchable) |
| `copies` | int | 1–50 |
| `idempotencyKey` | string | Repeating a key returns the first job instead of printing again |
| `scheduledFor` | `DateTimeInterface` or RFC 3339 string | Hold until then (≤ 30 days) |
| `options` | array | Extra print options passed to the station |

```php
$job = $catz->jobs->create([
    'content'        => file_get_contents('report.docx'),
    'fileName'       => 'report.docx',
    'role'           => 'office',
    'scheduledFor'   => new DateTimeImmutable('tomorrow 08:00'),
    'idempotencyKey' => 'report-2026-10-17',
]);
```

Unknown options throw `InvalidArgumentException` before anything is sent, so typos show up straight away.

### Reading and managing jobs

```php
$job = $catz->jobs->get($id);          // includes $job->events (status history)
foreach ($job->events as $e) {
    echo "{$e->at->format('H:i:s')} {$e->status} {$e->detail} ({$e->actor})\n";
}

$page = $catz->jobs->list([
    'status'   => ['failed', 'held'],     // or 'failed,held'
    'printerId'=> $printerId,             // also stationId, locationId, source, live, q, since, limit (1–200), counts
    'since'    => new DateTimeImmutable('-1 day'),
    'limit'    => 50,
]);
foreach ($page as $job) { /* this page */ }
$page->nextCursor;                       // null on the last page
$next = $page->nextPage();

foreach ($catz->jobs->all(['status' => 'printed']) as $job) {
    // every page, fetched lazily as you iterate
}

$catz->jobs->cancel($id);                              // scheduled, held, queued or sent jobs
$catz->jobs->retry($id);                               // failed or held jobs (operator-level key)
$catz->jobs->reprint($id, ['printerId' => $other, 'copies' => 2]);

$job = $catz->jobs->waitFor($id, timeout: 60, interval: 1);   // throws TimeoutException
$job->isPrinted();  // status === 'printed'
$job->isFinal();    // printed, failed or cancelled
```

Job statuses: `scheduled`, `held`, `queued`, `sent`, `printing`, `printed`, `failed`, `cancelled`.

### Printers, locations, templates

```php
foreach ($catz->printers->list() as $p) {
    echo "{$p->name} ({$p->role}, {$p->paper}) {$p->status}", $p->supports('pdf') ? ' PDF' : '', "\n";
}
$catz->printers->list(['stationId' => $stationId]);

$locations = $catz->locations->list();
$location  = $catz->locations->get($locationId);   // ->name, ->timeZone, ->printers, ->disabledJobPolicy…

$templates = $catz->templates->list();             // ->id, ->name, ->paper, ->body, ->version
```

All models are read-only objects with typed properties (`snake_case` API fields become `camelCase`; times are
`DateTimeImmutable`). Fields this SDK version doesn't know yet are still available: `$job['new_field']`, `$job->new_field`,
or `$job->toArray()`.

## Errors

Every error extends `CatzPrint\Exception\CatzPrintException`, which carries `status` (HTTP status, `0` without an answer),
`errorCode` (the API's stable code), `fields` (per-field messages), `retryAfter` (seconds) and `requestId`.

| Exception | When |
|---|---|
| `AuthenticationException` | 401 — missing, invalid, revoked or expired key |
| `PermissionException` | 403 — the key lacks a scope or role, or its IP isn't allowed |
| `NotFoundException` | 404 |
| `ValidationException` | 400 / 422 — see `$e->fields`, e.g. `['printer_id' => 'Unknown printer.']`; `no_printer` when routing finds none |
| `OutOfPrintsException` | 402 / `out_of_prints` — upgrade or buy more prints |
| `ConflictException` | 409 — e.g. cancelling a printed job, `printer_disabled` |
| `RateLimitException` | 429 — after retries; `$e->retryAfter` |
| `ServerException` | 5xx — after retries |
| `ConnectionException` | DNS, TLS, refused or timed out |
| `StationUnreachableException` | local printing: no station could take the job (`$e->attempts`, `$e->urls()`, `$e->cloudError`) |
| `PinMismatchException` | local printing: a station's certificate isn't the pinned one (a `ConnectionException`, in `attempts`) |
| `OutcomeUnknownException` | local printing: a job request was delivered but got no answer; it may have printed (`$e->url`, `$e->idempotencyKey`) |
| `PrinterException` | local printing: `503 printer_offline`, `out_of_paper`, `cover_open`, `printer_error` |
| `TimeoutException` | `jobs->waitFor()` ran out of time |
| `SignatureVerificationException` | a webhook signature didn't verify |

```php
use CatzPrint\Exception\{CatzPrintException, ValidationException, OutOfPrintsException};

try {
    $catz->printText('Hi', ['printerId' => $printerId]);
} catch (ValidationException $e) {
    foreach ($e->fields as $field => $message) { echo "$field: $message\n"; }
} catch (OutOfPrintsException $e) {
    notifyBilling($e->getMessage());
} catch (CatzPrintException $e) {
    error_log("CatzPrint {$e->status} {$e->errorCode}: {$e->getMessage()} (request {$e->requestId})");
}
```

## Retries and idempotency

Requests are retried up to twice (configurable with `maxRetries`) on network errors, `429` and `502/503/504`, with
exponential backoff and jitter (0.5 s, 1 s, … capped at 8 s). A `429`'s `Retry-After` / `retry_after_seconds` is respected.

A `POST` is **only** retried when it carries an idempotency key, so a retry can never print twice. `jobs->create()` (and every
print helper) always sends one: yours, or a fresh UUIDv4 that is reused for every retry of that call. Pass your own
`idempotencyKey` — an order number, say — to make your *own* retries safe too:

```php
$catz->printReceipt($commands, ['role' => 'receipt', 'idempotencyKey' => "order-{$order->id}-receipt"]);
```

`cancel`, `retry` and `reprint` are not retried automatically.

## Local printing (offline)

A restaurant must print when its internet is down. With the `local` option the SDK sends jobs straight to the CatzPrint Station (desktop or Android) on the same network (its local API on port 7443), and the station reports everything it printed offline
to the cloud when it's back: one job per idempotency key, billed, with webhooks (`$job->printedLocally`, `$job->offline`,
`$job->duplicateOf`).

Local printing needs ext-openssl (a standard extension, now in `require`). ext-sockets is optional (mDNS, below).

```php
use CatzPrint\Client;
use CatzPrint\Local\FileTokenStore;
use CatzPrint\Local\Mode;

$catz = new Client(getenv('CATZPRINT_API_KEY'), [
    'orgId' => $orgId, // saves a GET /v1/key per PHP request
    'local' => [
        'mode'       => Mode::LOCAL_FIRST,          // 'local_first'
        'locationId' => $locationId,                // minted tokens are bound to it
        'discover'   => true,                       // stations from the cloud's endpoint list (+ mDNS)
        'tokenStore' => new FileTokenStore('/var/lib/catzserve/catzprint-local.json'),
        'deviceName' => 'CatzServe hub',
    ],
]);

// A kitchen ticket: an inverted ALLERGY banner, then the order.
$job = $catz->jobs->create([
    'role'      => 'kitchen',
    'reference' => "order-{$order->id}",
    // One key per ticket and attempt: the station and the cloud both dedupe on
    // it, so a fallback or a retry never prints twice.
    'idempotencyKey' => "order-{$order->id}-kitchen-r1",
    'commands' => [
        ['type' => 'text', 'text' => ' ALLERGY: PEANUTS ', 'align' => 'center', 'bold' => true, 'invert' => true, 'size' => 2],
        ['type' => 'text', 'text' => 'Table 12', 'bold' => true, 'size' => 2],
        ['type' => 'line'],
        ['type' => 'columns', 'columns' => [['text' => '2 x', 'width' => 0.15], ['text' => 'Pad thai', 'bold' => true]]],
        ['type' => 'text', 'text' => '   no egg'],
        ['type' => 'cut'],
    ],
]);

// Or a saved template with data (templates are synced to the station):
$job = $catz->printTemplate($kitchenTemplateId, ['table' => '12', 'items' => $items], [
    'role'           => 'kitchen',
    'idempotencyKey' => "order-{$order->id}-kitchen-r1",
]);

echo $job->id, ' ', $job->status, ' ', $job->via, "\n"; // lj_… queued local   (or 6f1c… queued cloud)
```

`$job->via` is `"local"` when a station took the job (`$job->id` is then a local id `lj_…`, `$job->printedLocally` is true
and `$job->local` is the station's own view, a `LocalJob`) or `"cloud"` when the API did. `$catz->jobs->get('lj_…')` and
`waitFor('lj_…')` read local jobs from the station.

### Modes

| Mode | Behaviour |
|---|---|
| `Mode::CLOUD` (`'cloud'`, default) | Unchanged: every job goes to the API. |
| `Mode::LOCAL` (`'local'`) | Stations only. No station → `StationUnreachableException`. |
| `Mode::LOCAL_FIRST` (`'local_first'`) | Stations first; the cloud **only** when no station can be reached. |
| `Mode::CLOUD_FIRST` (`'cloud_first'`) | One cloud attempt (no retries, `cloudTimeout`); stations on a network error, timeout or 5xx. A 4xx (validation, 402, 403, 429…) is returned, not sent to a station. |

In `local_first` the SDK falls back to the cloud only when the stations are **unreachable**: connection, TLS, certificate
pin or timeout errors, `502`/`504`, and `503 local_disabled` (or a `503` without an error code). A station that answers is
never second-guessed; these are returned to you and never sent to the cloud:

| Station answer | PHP |
|---|---|
| `503 printer_offline` / `out_of_paper` / `cover_open` / `printer_error` (not accepted, not counted) | `PrinterException`; `$e->isPrinterOffline()`, `isOutOfPaper()`, `isCoverOpen()` |
| `422 no_printer` / `invalid` / `unsupported_locally`, `400` | `ValidationException` |
| `402 offline_allowance_exhausted` (offline too long / offline prints used) | `OutOfPrintsException`; `$e->isOfflineAllowanceExhausted()` |
| `403 device_not_approved` / `forbidden_location` | `PermissionException` |
| `409 duplicate` | `ConflictException` |
| `429 rate_limited` | `RateLimitException` |

**Never twice on an ambiguous outcome.** A failure *before* the whole request reached the station (connect, TLS, pin,
or a `502`/`504`/`503 local_disabled` refusal) is "not delivered": the next station, then the cloud, is safe. A failure
*after* the request was fully sent (timeout, connection reset, unreadable or oversized answer, a redirect) means the station
may have printed it, so the SDK asks **that same station** for the job by key (`GET /local/v1/jobs?idempotency_key=…`, up to
3 times within 5 s). A `2xx` without a readable job counts as no answer too. If the station has the job, it is returned;
if it answers `404 not_found`, it never recorded it and the SDK carries on as if not delivered; if it answers
`409 duplicate` (the key belongs to a job this device can't see), that `ConflictException` is returned (no fallback);
`409 in_progress` (still being processed) means keep asking. A job request answered `409 in_progress` is handled the
same way: the SDK waits for that job by key and never prints it anywhere else. Once any answer for the key (the job
request or a probe) said `409 in_progress`, a later `404` is not "never recorded" either: `OutcomeUnknownException`. If it still can't tell, you get an `OutcomeUnknownException` (`errorCode`
`outcome_unknown`, `$e->url`, `$e->idempotencyKey`, `$e->isOutcomeUnknown()`) and **nothing is sent anywhere else**.
The cloud leg works the same way: a network error before sending (including a failed organization lookup before the
job request), `502`, `503` or `504` counts as not delivered (cloud_first goes to the stations; local_first throws
`StationUnreachableException` with `cloudError`, or the cloud's own `4xx` error unwrapped); a timeout after sending, a redirect (never
followed), any other `5xx` or a `2xx` without a job is retried **once** with the same key (the body is built, and sealed,
once: every retry and re-send carries exactly the same bytes), and if that doesn't answer either it's
`OutcomeUnknownException`, never a station (the cloud may already have queued the job). A `409 duplicate` ("a job with
this key is being created") is re-sent unchanged after 1 s, up to 3 times, in every mode; a re-send that isn't delivered
or is ambiguous is `OutcomeUnknownException`, and a `409` after the last one is returned as the cloud's answer.
PHP has no cancellation API: if your script is stopped (time limit, client abort) after a job request was sent, treat the
job as "outcome unknown" and send it again later with the same key. Retrying later with the same idempotency key is always safe: the
station and the cloud both dedupe on it.

A `401 token_expired` makes the SDK mint a fresh token once and retry (at most once per 10 minutes per device, remembered in
the token store). Any other `401` (`device_revoked`, `unauthenticated`), or a token that can't be renewed because the cloud is
down too: `local_first` uses the cloud and `local` throws the `AuthenticationException`.

In every mode but `cloud` an idempotency key is always sent: yours, or `"sdk-<uuid v4>"`, and the **same** key goes to the
station and to the cloud. If a station printed a job and the cloud later gets the same key, the cloud merges them instead of
printing again. Use your own keys (like `order-1042-kitchen-r1`) so your own retries are safe too. An empty or all-blank key
counts as no key; a key with leading/trailing whitespace (including Unicode spaces, U+0085 and U+FEFF) or C0/C1 control
characters anywhere is refused with a `ValidationException` (field `idempotency_key`) rather than silently changed.

Jobs that can't print locally (PDFs, images, Office documents, sealed or scheduled jobs) go straight to the cloud in
`local_first`/`cloud_first` through the same create as cloud mode (the client's retries, the `409` re-sends, the
re-seal below) and throw a `ValidationException` with code `unsupported_locally` in `local` mode. Text,
commands, templates, raw ESC/POS and test prints work locally. When a job sets neither `printerId` nor `locationId`, the
`locationId` option is used.

```php
use CatzPrint\Exception\{CatzPrintException, OutcomeUnknownException, StationUnreachableException};

try {
    $job = $catz->jobs->create($ticket);
} catch (OutcomeUnknownException $e) {
    // it may have printed: retry later with $e->idempotencyKey, never with a new key
} catch (StationUnreachableException $e) {
    // no station, and (local_first / cloud_first) the cloud failed too
    error_log('tried ' . implode(', ', $e->urls()) . '; cloud: ' . $e->cloudError?->getMessage());
} catch (CatzPrintException $e) {
    if ($e->isPrinterOffline() || $e->isOutOfPaper() || $e->isCoverOpen()) {
        // tell the staff; send again later with the same idempotency key
    }
    throw $e;
}
```

`StationUnreachableException::$attempts` lists every URL tried with its error (`ConnectionException`,
`PinMismatchException`, …); `$e->isStationUnreachable()` is true for it.

### Finding and trusting stations

Stations are tried in this order: `stationUrls`, then (with `discover`) the URLs from the cloud's endpoint list (public
certificate hostnames before raw IPs), then (with `discover` and ext-sockets, only when those all failed) current
addresses found by a short mDNS browse (`_catzprint._tcp`, at most 1.5 s).

- **Public hostnames** (`https://192-168-1-20.<label>.local.catzprint.com:7443`) come **only** from the cloud endpoint list.
  They carry a Let's Encrypt certificate, verified like any website (chain and hostname; `caFile` overrides the CA bundle),
  **and** the certificate's public key must match the `public_spki_sha256` the cloud reports. The SDK connects to the IP in
  the name directly (so they work while public DNS is down) only for names under that station's own label.
- **IP URLs** are trusted **only** with a pinned SHA-256 fingerprint of the station's certificate: from the cloud endpoint
  list or the `fingerprints` option (the station app shows it). The fingerprint is checked right after the TLS handshake,
  **before a single request byte is sent**; a mismatch is a `PinMismatchException`. Only private addresses (RFC 1918, IPv6
  ULA, link-local, loopback) are used. Nothing is trusted on first use.
- **mDNS is never trusted on its own.** It only maps a station ID to its current address, for a station on the cloud
  endpoint list (pinned with the list's fingerprint) or one whose announced fingerprint is in `fingerprints`. The announced
  hostname is ignored. So a device on the LAN can't pose as your station. Without ext-sockets mDNS is skipped.

Stations never go through a proxy and redirects are never followed.

### Tokens, the token store and refreshes

The local token (`czl1…`) is minted with your API key (`POST /v1/orgs/{org}/local-tokens`, scope `jobs:write`) and **bound
to `locationId`** (required unless you set `'allLocations' => true`). It is kept in the token store with the endpoint list.

PHP usually runs once per web request, so the SDK has no background work. Instead it refreshes **lazily**, after a station
answered: the token when it has less than min(7 days, half its lifetime) left, the endpoint list when it is older than an hour, and only when the
cloud answers. A failed refresh is not tried again for 5 minutes (remembered in the store), so a dead internet line costs
at most one `cloudTimeout` wait every 5 minutes. To keep refreshes out of web requests entirely, set
`'autoRefresh' => false` and call `$catz->local->refresh()` from a cron job.

Use a persistent store in production: the default `MemoryTokenStore` lives for one PHP request, so every request would
mint a new token (and a hub restarted offline couldn't find or trust its stations). `new FileTokenStore($path)` writes a
JSON file with mode 0600 (its directory 0700) through a temporary file, `fsync` and an atomic rename, and serializes PHP
processes with `flock()` on `<path>.lock`; keep it outside the web root. On POSIX systems an existing store file, lock file
or directory that isn't owned by the PHP process's effective user (found with ext-posix, or else from a probe file
created in the store directory; if neither works the store is refused), or is group- or world-writable, is refused (reported by `storeError()`,
never used), so don't put it straight into a shared directory like `/tmp`. One file holds several clients (the key is a
hash of the API key plus the location and device name, never the key itself). Implement `CatzPrint\Local\TokenStore`
(`load` / `save`, and `AtomicTokenStore::update` for cross-process rate limits) to keep it in Redis or a database.
Store failures never stop printing: see `$catz->local->storeError()`.

### Options

| `local` option | Default | |
|---|---|---|
| `mode` | `cloud` | `cloud`, `local`, `local_first`, `cloud_first` |
| `locationId` | — | The location this device prints at: binds minted tokens, filters stations, default `locationId` of jobs |
| `allLocations` | `false` | Mint tokens valid at every location the API key may use |
| `stationUrls` | `[]` | Station URLs to try first, e.g. `https://192.168.1.20:7443` |
| `fingerprints` | `[]` | Pins you got from the station app: key = URL, `host:port` or host; value = hex SHA-256 (colons allowed) |
| `discover` | `false` | Use the cloud endpoint list (and mDNS with ext-sockets) |
| `tokenStore` | memory | A `CatzPrint\Local\TokenStore`, e.g. `FileTokenStore` |
| `token` | — | A fixed token instead of minting: a device token from `pair()` (`czd_…`) or a local token (`czl1.…`) |
| `deviceName` | host name | Names this device on stations (≤ 80 characters) |
| `tokenTtlDays` | 30 | Lifetime of minted tokens, 1–30 |
| `connectTimeout` | 3 | Seconds to connect to a station, TLS handshake included |
| `requestTimeout` | 15 | Seconds for one station request |
| `cloudTimeout` | 10 | Seconds for the cloud attempt in `cloud_first`, minting and refreshes |
| `discoverTimeout` | 1.5 | Seconds an mDNS browse listens (at most 1.5) |
| `wait` | 0 | Ask the station to answer once the job printed or failed, up to 30 seconds |
| `caFile` | system | PEM bundle for verifying public-certificate station hostnames |
| `autoRefresh` | `true` | Lazy token / endpoint refreshes (see above) |

### Local helpers

```php
$st = $catz->local->status();            // LocalStatus: printers, paper, unsynced jobs, allowance left
$stations = $catz->local->discover();    // list<LocalStation>: endpoint list + mDNS, merged
$info = $catz->local->info($url);        // LocalInfo: GET /local/v1/info
$job = $catz->local->getJob('lj_…');      // same as $catz->jobs->get('lj_…')
$catz->local->refresh();                 // renew token / endpoint list now (cron)
$token = $catz->local->token();          // the czl1 token, e.g. for a browser POS using catzprint/local

// Pair a till with one station using the 8-digit code AND the certificate fingerprint shown in
// the station app (or a station on the cloud endpoint list). Without either it refuses.
$pairing = $catz->local->pair('https://192.168.1.20:7443', '12345678', 'Till 3', '3f1a…');

// A paired till needs no API key: local mode with the device token.
$till = new Client(null, ['local' => [
    'mode'         => Mode::LOCAL,
    'stationUrls'  => ['https://192.168.1.20:7443'],
    'fingerprints' => ['192.168.1.20' => '3f1a…'],
    'token'        => $pairing->token,
]]);
// (its cloud calls throw AuthenticationException with errorCode "missing_api_key")

$lt = $catz->localTokens->create(['deviceName' => 'Till 3', 'locationIds' => [$locationId], 'ttlDays' => 30]);
$catz->localTokens->revoke($lt->tokenId);
$endpoints = $catz->localEndpoints->list($locationId);   // list<LocalEndpoint>
```

## Sealed printing

With `'sealed' => true` the SDK encrypts the document to CatzPrint's public key (`GET /v1/public/server-key`, cached for five
minutes) as a NaCl sealed box (X25519 + XSalsa20-Poly1305, libsodium `crypto_box_seal`) and sends it as `sealed_content`. Only
the server can open it; it is then sealed again to the receiving station's own key.

```php
$catz->printPdf('/secure/payslip.pdf', ['printerId' => $officePrinterId, 'sealed' => true]);
$catz->printText("PIN: 4821\n", ['role' => 'receipt', 'sealed' => true]);
```

Needs `ext-sodium` (bundled with PHP since 7.2). If the server key was rotated (a `400`/`422` naming `sealed_content`),
the SDK fetches it again and re-seals once, with the same idempotency key. After an ambiguous attempt (an answer lost, a
client retry, a `409 duplicate`), a failed key fetch or a re-send that is rejected again, not delivered or ambiguous is an
`OutcomeUnknownException` (never a `ValidationException`); without one, a repeated rejection is the `ValidationException`.
Commands and template data are JSON and can't be sealed (they are still protected by TLS and sealed to the station).

## Webhooks

CatzPrint signs every delivery with `CatzPrint-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, t + "." + body)>`.
`Webhook::verify()` checks it in constant time, rejects timestamps more than 300 s off (configurable), accepts multiple `v1`
values (secret rotation) and returns the parsed event. Always verify the **raw** request body.

Every event carries `$event->id`, the delivery id, which stays the same across retries. **Deduplicate on `$event->id`**:
the timestamp check limits the replay window to 5 minutes, it does not stop a captured delivery from being replayed
within that window (and retries can deliver the same event twice).

```php
use CatzPrint\Webhook;
use CatzPrint\Exception\SignatureVerificationException;

$event = Webhook::verify($rawBody, $signatureHeader, $secret);        // tolerance: 300 s
$event = Webhook::constructEvent($rawBody, $signatureHeader, $secret); // Stripe-style alias
$event = $catz->webhooks->verify($rawBody, $signatureHeader, $secret);

$event->id;        // delivery id, same across retries: deduplicate on it
$event->type;      // job.created, job.printed, job.failed, job.cancelled, job.retried,
                   // station.online, station.offline, printer.status
$event->orgId;
$event->createdAt; // DateTimeImmutable
$event->data;      // array
$event->job();     // CatzPrint\Model\Job for job.* events
```

### Laravel

```php
// routes/api.php  (the api group has no CSRF check)
use App\Http\Controllers\CatzPrintWebhookController;

Route::post('/webhooks/catzprint', CatzPrintWebhookController::class);
```

```php
// app/Http/Controllers/CatzPrintWebhookController.php
namespace App\Http\Controllers;

use App\Jobs\MarkOrderPrinted;
use CatzPrint\Exception\SignatureVerificationException;
use CatzPrint\Webhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CatzPrintWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::verify(
                $request->getContent(),                         // the raw body, not $request->all()
                $request->header('CatzPrint-Signature'),
                config('services.catzprint.webhook_secret'),    // whsec_… from the console
            );
        } catch (SignatureVerificationException $e) {
            return response('bad signature', 400);
        }

        match ($event->type) {
            'job.printed' => MarkOrderPrinted::dispatch($event->job()->reference),
            'job.failed'  => logger()->warning('Print failed', ['job' => $event->job()->id, 'why' => $event->job()->statusDetail]),
            default       => null,
        };

        return response()->noContent(); // answer quickly; do slow work in queued jobs
    }
}
```

```php
// config/services.php
'catzprint' => [
    'key'            => env('CATZPRINT_API_KEY'),
    'webhook_secret' => env('CATZPRINT_WEBHOOK_SECRET'),
],
```

If you register the route in `routes/web.php` instead, exclude it from CSRF protection (`VerifyCsrfToken::$except`, or
`$middleware->validateCsrfTokens(except: ['webhooks/catzprint'])` in Laravel 11+). To send jobs from Laravel, bind the client
once:

```php
// AppServiceProvider::register()
$this->app->singleton(\CatzPrint\Client::class, fn () => new \CatzPrint\Client(config('services.catzprint.key')));
```

To test your own endpoint, `Webhook::sign($body, $secret)` builds a header exactly like CatzPrint does.

## Test and live keys

| | `cz_test_…` | `cz_live_…` |
|---|---|---|
| Prints on real printers | yes | yes |
| Billed / counts against allowance | never (`live: false`, `billedAs: "test"`) | yes |
| Daily limit | 200 test prints | your plan |

Use a test key in development and CI, a live key in production. `$catz->isLive()` tells you which one the client holds;
`$catz->key()->live` asks the API.

## Examples

Runnable scripts in [`examples/`](examples):

- `print_receipt.php` — a receipt from commands
- `print_pdf_and_wait.php` — seal a PDF, print it and wait for the result
- `verify_webhook.php` — a plain-PHP webhook endpoint (run it from the CLI for a demo)

```bash
CATZPRINT_API_KEY=cz_test_… php examples/print_receipt.php
```

## Developing the SDK

```bash
php tests/run.php                       # unit tests, no Composer or PHPUnit needed
CATZPRINT_TEST_FIXTURE=fixture.json php tests/run.php   # plus integration tests against a live API
vendor/bin/phpunit                      # the same test classes under PHPUnit, after composer install
```

The unit tests use a local fake HTTP server (`php -S`) for retries and request shapes, webhook vectors produced by the
server's own signing code (`tests/fixtures/gen_webhook_vectors.go`), and PyNaCl (if installed) to prove sealed boxes open.
The local printing tests run a fake station over real TLS (`tests/Support/fake_station.php`, a `stream_socket_server` in a
background process) with the certificates in `tests/fixtures/local` (a self-signed station certificate for `127.0.0.1`, and
a test CA with a `*.abcdefghij.local.catzprint.com` leaf), a fake cloud (`php -S`) and, with ext-sockets, a unicast mDNS
responder.

## License

MIT © Catalizo
