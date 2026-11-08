<?php

declare(strict_types=1);

/*
 * A fake CatzPrint station (local API, spec §4.2) over real TLS, for the
 * local printing tests. php -S can't do TLS, so this is a small
 * stream_socket_server loop run as a background process by FakeStation.
 *
 *   php fake_station.php <dir> <cert.pem> <key.pem>
 *
 * <dir>/port    written once listening (the port number)
 * <dir>/config  JSON written by the test (read on every request):
 *               accept: null (any czl1./czd_ token) | list of accepted token jtis/tokens
 *               rejectCode: 401 code for refused tokens (default token_expired)
 *               fail: {"<METHOD> <path>": [status, code]}   (path without query)
 *               failOnce: like fail, for the first matching request only
 *               delay: seconds before answering
 *               live: null | bool  (LocalJob.live)
 *               drop: {"<METHOD> <path>": "record" | "norecord" | "empty"}  read the request, then close
 *                     without answering ("record": a job POST is still recorded first; "empty": record,
 *                     then answer 201 with "{}"; "inprogress": record, then answer 409 in_progress;
 *                     "reset": close with a TCP reset (SO_LINGER 0), needs ext-sockets)
 * <dir>/log     JSON lines appended by this server:
 *               {"type":"conn","bytes":N,"tls":bool}  one per connection (bytes after the handshake)
 *               {"type":"req","method","path","query","auth","body"}
 *
 * Jobs live in memory. One connection at a time (Connection: close).
 */

[$dir, $certFile, $keyFile] = [$argv[1], $argv[2], $argv[3]];
$failedOnce = [];
$ctx = stream_context_create(['ssl' => ['local_cert' => $certFile, 'local_pk' => $keyFile]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "listen: $errstr\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents("$dir/port.tmp", substr($name, strrpos($name, ':') + 1));
rename("$dir/port.tmp", "$dir/port");

$log = static function (array $entry) use ($dir): void {
    file_put_contents("$dir/log", json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
};
$config = static function () use ($dir): array {
    $c = @json_decode((string) @file_get_contents("$dir/config"), true);

    return is_array($c) ? $c : [];
};
$jobs = [];
$byKey = [];
$nextId = 0;
$fp = strtolower((string) openssl_x509_fingerprint((string) file_get_contents($certFile), 'sha256'));
$started = time();

$respond = static function ($conn, int $status, mixed $json): void {
    $body = json_encode($json, JSON_UNESCAPED_SLASHES);
    $reason = [200 => 'OK', 201 => 'Created', 204 => 'No Content'][$status] ?? 'Status';
    @fwrite($conn, "HTTP/1.1 $status $reason\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
};
$err = static function ($conn, int $status, string $code) use ($respond): void {
    $respond($conn, $status, ['error' => ['code' => $code, 'message' => "Station says $code."]]);
};
$jti = static function (string $tok): string {
    $p = explode('.', $tok);
    if (count($p) !== 3) {
        return '';
    }
    $c = json_decode((string) base64_decode(strtr($p[1], '-_', '+/')), true);

    return is_array($c) ? (string) ($c['jti'] ?? '') : '';
};

while (time() - $started < 600 && is_dir($dir)) {
    $conn = @stream_socket_accept($server, 0.5);
    if ($conn === false) {
        continue;
    }
    $cfg = $config();
    stream_set_blocking($conn, true);
    stream_set_timeout($conn, 3);
    $ok = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    if ($ok !== true) {
        @fclose($conn);
        $log(['type' => 'conn', 'bytes' => 0, 'tls' => false]);
        continue;
    }
    // Read the request: headers, then Content-Length bytes. A client that
    // closes right after the handshake sends nothing.
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n")) {
        $chunk = @fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    $headEnd = strpos($raw, "\r\n\r\n");
    if ($headEnd === false) {
        $log(['type' => 'conn', 'bytes' => strlen($raw), 'tls' => true]);
        @fclose($conn);
        continue;
    }
    $head = substr($raw, 0, $headEnd);
    $body = substr($raw, $headEnd + 4);
    $headers = [];
    $lines = explode("\r\n", $head);
    [$method, $target] = explode(' ', array_shift($lines)) + [1 => '/'];
    foreach ($lines as $l) {
        [$k, $v] = array_pad(explode(':', $l, 2), 2, '');
        $headers[strtolower(trim($k))] = trim($v);
    }
    $len = (int) ($headers['content-length'] ?? 0);
    while (strlen($body) < $len) {
        $chunk = @fread($conn, $len - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    $log(['type' => 'conn', 'bytes' => strlen($head) + 4 + strlen($body), 'tls' => true]);
    $path = (string) parse_url($target, PHP_URL_PATH);
    $query = (string) parse_url($target, PHP_URL_QUERY);
    $auth = $headers['authorization'] ?? '';
    $data = json_decode($body, true);
    $data = is_array($data) ? $data : [];
    $log(['type' => 'req', 'method' => $method, 'path' => $path, 'query' => $query, 'auth' => $auth, 'body' => $data, 'host' => $headers['host'] ?? '']);

    if (!empty($cfg['delay'])) {
        usleep((int) ($cfg['delay'] * 1_000_000));
    }
    $drop = $cfg['drop'][$method . ' ' . $path] ?? null;
    if ($drop !== null) {
        if (in_array($drop, ['record', 'empty', 'inprogress'], true) && $method === 'POST' && $path === '/local/v1/jobs') {
            $nextId++;
            $id = sprintf('lj_%016x', $nextId);
            $key = (string) ($data['idempotency_key'] ?? '');
            $jobs[$id] = ['id' => $id, 'idempotency_key' => $key, 'status' => 'queued', 'detail' => '', 'printer_id' => 'p-kitchen',
                'printer_name' => 'Kitchen', 'role' => $data['role'] ?? '', 'location_id' => 'loc1', 'copies' => (int) ($data['copies'] ?? 1),
                'title' => '', 'reference' => '', 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'finished_at' => null,
                'printed_locally' => true, 'via' => 'local', 'cloud_job_id' => null];
            if ($key !== '') {
                $byKey[$key] = $id;
            }
        }
        if ($drop === 'empty') {
            $respond($conn, 201, new stdClass());
        }
        if ($drop === 'inprogress') {
            $err($conn, 409, 'in_progress');
        }
        if ($drop === 'reset' && function_exists('socket_import_stream')) {
            $raw = socket_import_stream($conn);
            if ($raw !== false && $raw !== null) {
                socket_set_option($raw, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                socket_close($raw);
            }
        }
        @fclose($conn);
        continue;
    }
    $fail = $cfg['fail'][$method . ' ' . $path] ?? null;
    $once = $cfg['failOnce'][$method . ' ' . $path] ?? null;
    if ($fail === null && is_array($once) && !isset($failedOnce[$method . ' ' . $path])) {
        $failedOnce[$method . ' ' . $path] = true;
        $fail = $once;
    }
    if (is_array($fail)) {
        if (($fail[1] ?? '') === '') {
            $respond($conn, (int) $fail[0], ['error' => ['message' => 'No code.']]);
        } else {
            $err($conn, (int) $fail[0], (string) $fail[1]);
        }
        fclose($conn);
        continue;
    }

    if ($method === 'GET' && $path === '/local/v1/info') {
        $respond($conn, 200, ['station_id' => 'st1', 'name' => 'Kitchen PC', 'org_id' => 'org1', 'location_id' => 'loc1',
            'version' => '3.0.0', 'port' => 7443, 'tls_mode' => 'self_signed', 'cert_fingerprint_sha256' => $fp,
            'hostnames' => [], 'addresses' => ['127.0.0.1'], 'pairing_open' => true, 'time' => gmdate('Y-m-d\TH:i:s\Z')]);
    } elseif ($method === 'POST' && $path === '/local/v1/pair') {
        if (($data['code'] ?? '') !== '12345678') {
            $err($conn, 403, 'invalid_code');
        } else {
            $respond($conn, 201, ['device_id' => 'd_0123456789abcdef', 'token' => 'czd_paired', 'station_id' => 'st1', 'org_id' => 'org1', 'location_id' => 'loc1']);
        }
    } else {
        $tok = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        $accepted = str_starts_with($tok, 'czl1.') || str_starts_with($tok, 'czd_');
        if (array_key_exists('accept', $cfg) && $cfg['accept'] !== null) {
            $accepted = in_array($jti($tok), (array) $cfg['accept'], true) || in_array($tok, (array) $cfg['accept'], true);
        }
        if (!$accepted) {
            $err($conn, 401, (string) ($cfg['rejectCode'] ?? 'token_expired'));
        } elseif ($method === 'POST' && $path === '/local/v1/jobs') {
            $key = (string) ($data['idempotency_key'] ?? '');
            if ($key !== '' && isset($byKey[$key])) {
                $respond($conn, 200, $jobs[$byKey[$key]]);
            } else {
                $nextId++;
                $id = sprintf('lj_%016x', $nextId);
                parse_str($query, $q);
                $printed = isset($q['wait']);
                $j = ['id' => $id, 'idempotency_key' => $key, 'status' => $printed ? 'printed' : 'queued', 'detail' => '',
                    'printer_id' => 'p-kitchen', 'printer_name' => 'Kitchen', 'role' => $data['role'] ?? '', 'location_id' => 'loc1',
                    'copies' => (int) ($data['copies'] ?? 1), 'title' => $data['title'] ?? '', 'reference' => $data['reference'] ?? '',
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'finished_at' => $printed ? gmdate('Y-m-d\TH:i:s\Z') : null,
                    'printed_locally' => true, 'via' => 'local', 'cloud_job_id' => null];
                if (array_key_exists('live', $cfg) && $cfg['live'] !== null) {
                    $j['live'] = (bool) $cfg['live'];
                }
                $jobs[$id] = $j;
                if ($key !== '') {
                    $byKey[$key] = $id;
                }
                $respond($conn, 201, $j);
            }
        } elseif ($method === 'GET' && $path === '/local/v1/jobs') {
            parse_str($query, $q);
            $key = (string) ($q['idempotency_key'] ?? '');
            if ($key === '' || !isset($byKey[$key])) {
                $err($conn, 404, 'not_found');
            } else {
                $respond($conn, 200, $jobs[$byKey[$key]]);
            }
        } elseif ($method === 'GET' && str_starts_with($path, '/local/v1/jobs/')) {
            $id = rawurldecode(substr($path, strlen('/local/v1/jobs/')));
            if (!isset($jobs[$id])) {
                $err($conn, 404, 'not_found');
            } else {
                $jobs[$id]['status'] = 'printed';
                $jobs[$id]['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $respond($conn, 200, $jobs[$id]);
            }
        } elseif ($method === 'GET' && $path === '/local/v1/status') {
            $respond($conn, 200, [
                'station' => ['id' => 'st1', 'name' => 'Kitchen PC', 'cloud_connected' => false, 'last_cloud_contact' => '', 'version' => '3.0.0', 'local_enabled' => true],
                'printers' => [['id' => 'p-kitchen', 'name' => 'Kitchen', 'role' => 'kitchen', 'status' => 'ready', 'paper' => 'ok', 'jobs_waiting' => 0]],
                'jobs_waiting' => 0, 'unsynced' => 3, 'allowance' => ['expires_at' => '2026-11-14T00:00:00Z', 'prints_left' => 1997],
            ]);
        } else {
            $err($conn, 404, 'not_found');
        }
    }
    fclose($conn);
}
