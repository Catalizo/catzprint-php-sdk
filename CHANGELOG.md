# Changelog

All notable changes to `catzprint/catzprint-php` are documented here. This project follows [Semantic Versioning](https://semver.org).

## 2.1.3 — 2026-10-17

### Security

- The base URL must use `https://`; `http://` is accepted only for `localhost`, `127.0.0.1`, `::1` and `*.localhost`, so
  the API key is never sent in clear text.
- Redirects are never followed, so the API key only reaches the configured host.
- Response bodies are capped at 64 MiB; a larger answer is an error.
- Webhook verification hardened: an empty secret is rejected, the timestamp must be all digits, and every `v1` signature
  is compared in constant time.
- File reads in `printPdf` / `printImage` hardened: stream wrappers (`phar://`, `php://`, `data://`, `http://`…) are
  never opened, `file://` URLs are read as plain local paths, and a string that names an existing file is only read when
  the file really is a PDF / PNG / JPEG, so untrusted content can't make the SDK print an arbitrary local file.
- `Event::$id`: the delivery id the server now includes in every signed event (`null` if absent). Deduplicate on
  `$event->id`; the timestamp check limits the replay window to 5 minutes, it does not stop replays within it.

## 1.0.0 — 2026-10-16

First release.

- `CatzPrint\Client` with API key from the constructor or `CATZPRINT_API_KEY`, base URL from options or `CATZPRINT_BASE_URL`,
  organization looked up once with `GET /v1/key` (`key()` / `whoami()`).
- Jobs: `create`, `get` (with event history), `list` with cursor pagination and `all()` auto-paging, `cancel`, `retry`,
  `reprint`, `waitFor`.
- Helpers: `printText`, `printPdf`, `printImage`, `printReceipt`, `printTemplate`.
- Sealed printing (`'sealed' => true`) with NaCl sealed boxes via ext-sodium, cached server key, re-seal on key rotation.
- `printers->list`, `locations->list` / `get`, `templates->list`.
- Webhook signature verification (`Webhook::verify` / `constructEvent`, multiple `v1` values, timestamp tolerance) and
  `Webhook::sign` for testing.
- Typed, read-only models (`Job`, `JobEvent`, `JobPage`, `Printer`, `Location`, `Template`, `Key`, `Event`) that keep unknown fields.
- Exceptions per error kind, automatic retries with exponential backoff and jitter on network errors, 429 and 502/503/504;
  POSTs retried only with an idempotency key (always sent by `jobs->create`).
- No Composer dependencies: ext-curl and ext-json (ext-sodium for sealing).
