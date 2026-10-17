# CatzPrint PHP SDK

The official PHP client for the [CatzPrint](https://catzprint.com) printing API. Print receipts, PDFs, images and templates on
your printers, seal documents end to end, follow jobs until they print, and verify webhooks.

- PHP 8.1+, **no Composer dependencies** — just `ext-curl` and `ext-json` (`ext-sodium`, bundled with PHP, for sealed printing)
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

## Sealed printing

With `'sealed' => true` the SDK encrypts the document to CatzPrint's public key (`GET /v1/public/server-key`, cached for five
minutes) as a NaCl sealed box (X25519 + XSalsa20-Poly1305, libsodium `crypto_box_seal`) and sends it as `sealed_content`. Only
the server can open it; it is then sealed again to the receiving station's own key.

```php
$catz->printPdf('/secure/payslip.pdf', ['printerId' => $officePrinterId, 'sealed' => true]);
$catz->printText("PIN: 4821\n", ['role' => 'receipt', 'sealed' => true]);
```

Needs `ext-sodium` (bundled with PHP since 7.2). If the server key was rotated, the SDK fetches it again and re-seals once.
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

## License

MIT © Catalizo
