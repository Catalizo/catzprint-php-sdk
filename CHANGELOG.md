# Changelog

All notable changes to `catzprint/catzprint-php` are documented here. This project follows [Semantic Versioning](https://semver.org).

## 2.2.1 — 2026-11-07

Security audit round 7 (no API changes).

- A re-sealed re-send after a `sealed_content` `400`/`422` that follows an ambiguous attempt (an answer lost, a
  client retry, a `409 duplicate`) and is rejected again, not delivered or ambiguous — or whose key fetch fails — is
  now `OutcomeUnknownException`, never a `ValidationException`. Without an ambiguous attempt, a repeated rejection
  is still the `ValidationException`.
- `cloud_first`: jobs that can't print locally (sealed, PDF/image/document, scheduled) use the same create as cloud mode (the client's retries, the `409` re-sends, the re-seal), like the other SDKs.
- Stored token placeholders are recognised by the same set in every SDK: `…`, a run of 3+ `*`, `[redacted]`, `redacted`, `hidden` (any case), optionally after `czl1.` or `czd_`. (Any other non-token value was, and still is, treated as "no token".)
- A cloud `3xx` has the error code `unexpected_redirect` (was an empty code).
- Once any answer for the key said `409 in_progress` (the job request or a probe by key), a later probe `404 not_found` is `OutcomeUnknown` (no next station, no cloud).

## 2.2.0 — 2026-11-07

### Local printing (offline)

Print through the CatzPrint desktop station on the same network when the internet is down; the station reports what it
printed to the cloud later (one job per idempotency key). Same behaviour as the Go and Node SDKs 2.2.0. See the README,
"Local printing (offline)".

- New client option `local` with `mode`: `cloud` (default, unchanged), `local`, `local_first`, `cloud_first`
  (`CatzPrint\Local\Mode`), plus `locationId`, `stationUrls`, `fingerprints`, `discover`, `allLocations`, `tokenStore`,
  `token`, `deviceName`, `tokenTtlDays`, `connectTimeout`, `requestTimeout`, `cloudTimeout`, `discoverTimeout`, `wait`,
  `caFile`, `autoRefresh`.
- Fallback only when no station is reachable (connect / TLS / pin / timeout errors, `502`, `504`, `503 local_disabled` or a
  `503` without a code); never on `printer_*`, `out_of_paper`, `cover_open`, `no_printer`, `invalid`,
  `unsupported_locally`, `402`, `403`, `409`, `429`. `cloud_first` makes a single cloud attempt. The same idempotency key
  (`sdk-<uuid v4>` when none is given) goes to the station and to the cloud.
- Local tokens minted with the API key, bound to `locationId` (unless `allLocations`), cached in a `TokenStore`
  (`FileTokenStore`: 0600, atomic rename, `flock`; `MemoryTokenStore`), refreshed lazily (token < 7 days left, endpoint list
  older than an hour, only while the cloud answers). A station's `401 token_expired` re-mints at most once per 10 minutes
  per device (persisted); `device_revoked` / `unauthenticated` never re-mint.
- TLS: IP and self-signed stations only with a pinned SHA-256 certificate fingerprint, checked before any request byte is
  sent (`PinMismatchException`); public hostnames verified normally plus a `public_spki_sha256` pin, dialled at the IP in
  the name only under the station's own label. Only private addresses are used. Optional mDNS browse (ext-sockets, ≤ 1.5 s)
  only maps stations on the cloud list or with a pinned fingerprint to their current address.
- `$catz->local`: `mode()`, `status()`, `discover()`, `info()`, `pair()` (needs the station app's fingerprint or a station on
  the cloud list), `getJob()`, `refresh()`, `token()`, `storeError()`, `refreshError()`. `$catz->localTokens->create()` /
  `revoke()`, `$catz->localEndpoints->list()`. `jobs->get()` / `waitFor()` read `lj_…` ids from the station.
- A paired device needs no API key in `local` mode with a `token`; its cloud calls throw `AuthenticationException`
  `missing_api_key`.
- `Job` gains `via`, `printedLocally`, `localDevice`, `duplicateOf`, `offline`, `idempotencyKey` and `local` (`LocalJob`).
- New exceptions `StationUnreachableException` (`attempts`, `urls()`, `cloudError`), `PrinterException`,
  `PinMismatchException`; `CatzPrintException` gains `isPrinterOffline()`, `isOutOfPaper()`, `isCoverOpen()`,
  `isOfflineAllowanceExhausted()`, `isStationUnreachable()`.
- Ambiguous outcomes never print twice: a station request that was fully sent but got no answer is followed by up to 3
  look-ups by idempotency key on the same station (within 5 s); still unknown → `OutcomeUnknownException`
  (`outcome_unknown`), no fallback. A cloud attempt that timed out after sending, or a `500`, is retried once with the same
  key, then `OutcomeUnknownException`, never a station. `ConnectionException::requestDelivered()`.
- Audit round 5: a key look-up after an ambiguous station request that answers `409 duplicate` is returned as the answer
  (only `404 not_found` means "never recorded"); a cloud `409 duplicate` is re-sent after 1 s up to 3 times; any `5xx`
  other than `502/503/504` and a `2xx` without a job are ambiguous; a failed organization lookup before the job request
  is "not delivered"; `local_first` returns the cloud's `4xx` unwrapped; the request deadline is no longer checked after
  the final write; keys with Unicode whitespace at either end or C0/C1 controls are refused; without ext-posix the
  token store finds the effective user with a probe file (and refuses the store if it can't).
- Audit round 6: a station's `409 in_progress` (to a job request or a key look-up) means "wait for it by key", never a
  fallback; the cloud body is built and sealed once per create, so the ambiguous-outcome retry, the 409 re-sends and the
  client's own retries send identical bytes; a cloud `3xx` after the job request is ambiguous (one retry, then
  `OutcomeUnknownException`); station socket reads never emit PHP warnings; a token store entry that isn't a local token
  (e.g. a redacted placeholder) is ignored and reported by `storeError()`. `json_encode()` of `LocalToken`,
  `LocalPairing` and `StoredToken::toArray()` include the token (only `var_dump`/`print_r` output is redacted).
- Idempotency keys: blank means none; leading/trailing whitespace or control characters → `ValidationException`
  (previously trimmed silently).
- Tokens are renewed when less than min(7 days, half their lifetime) is left.
- `FileTokenStore` refuses (POSIX) an existing file, lock file or directory not owned by the current user or writable by
  group/others.
- `#[\SensitiveParameter]` on every parameter that carries a token, API key, webhook secret or pairing code; tokens are
  never kept in plain closure variables or debug output (`LocalConfig`, `LocalState`, `MemoryTokenStore` redact).
- The mDNS collector keeps at most 256 instances / hosts and 32 addresses per host.
- `ext-openssl` is now required (a standard extension; used for the station TLS checks). `ext-sockets` is suggested.

## 2.1.4 — 2026-10-20

- Docs only: the README explains that printer names aren't unique across stations, how to target printers by ID, and the console's copy-ID shortcut.

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
