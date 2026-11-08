<?php

declare(strict_types=1);

/*
 * Router for `php -S`: a fake CatzPrint API for the local printing tests
 * (org "org1"). Config is read from $FAKE_CLOUD_DIR/config (written by the
 * test): down, jobStatus, jobCode, mintStatus, mintCode, stations,
 * expiresIn (seconds). Every request is recorded in $FAKE_CLOUD_DIR/log.
 */

$dir = (string) getenv('FAKE_CLOUD_DIR');
$cfg = json_decode((string) @file_get_contents("$dir/config"), true);
$cfg = is_array($cfg) ? $cfg : [];
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$fh = fopen("$dir/log", 'a+');
flock($fh, LOCK_EX);
$prior = 0;
$priorJobs = 0;
rewind($fh);
while (($line = fgets($fh)) !== false) {
    $e = json_decode($line, true);
    if (is_array($e) && $e['method'] === 'POST' && str_ends_with($e['path'], '/local-tokens')) {
        $prior++;
    }
    if (is_array($e) && $e['method'] === 'POST' && $e['path'] === '/v1/orgs/org1/jobs') {
        $priorJobs++;
    }
}
fwrite($fh, json_encode(['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body,
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? ''], JSON_UNESCAPED_SLASHES) . "\n");
flock($fh, LOCK_UN);
fclose($fh);

header('Content-Type: application/json');
$err = static function (int $status, string $code): void {
    http_response_code($status);
    echo json_encode(['error' => ['code' => $code, 'message' => "Cloud says $code."]]);
};
if (!empty($cfg['down'])) {
    $err(503, 'unavailable');

    return true;
}
$token = static function (string $jti, bool $live): string {
    $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

    return 'czl1.' . $b64((string) json_encode(['v' => 1, 'kid' => 'k1', 'jti' => $jti, 'org' => 'org1', 'key' => 'key1', 'loc' => null, 'dev' => 'hub', 'live' => $live, 'iat' => 1, 'exp' => 2])) . '.c2ln';
};

if ($method === 'POST' && $path === '/v1/orgs/org1/local-tokens') {
    if (!empty($cfg['mintStatus'])) {
        $err((int) $cfg['mintStatus'], (string) ($cfg['mintCode'] ?? 'nope'));

        return true;
    }
    $jti = 'jti-' . ($prior + 1);
    http_response_code(201);
    $out = ['token' => $token($jti, true), 'token_id' => $jti, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + (int) ($cfg['expiresIn'] ?? 30 * 86400))];
    if (array_key_exists('stations', $cfg)) {
        $out['stations'] = $cfg['stations'];
    }
    echo json_encode($out);
} elseif ($method === 'DELETE' && str_starts_with($path, '/v1/orgs/org1/local-tokens/')) {
    http_response_code(204);
} elseif ($method === 'GET' && $path === '/v1/orgs/org1/local-endpoints') {
    echo json_encode(['stations' => $cfg['stations'] ?? []]);
} elseif ($method === 'POST' && $path === '/v1/orgs/org1/jobs') {
    // jobSeq: per job POST (0-based), "ok", "empty", "seal400", [status, code] or {"delay": seconds}
    $step = $cfg['jobSeq'][$priorJobs] ?? null;
    if ($step === 'seal400') { // the server key was rotated: the sealed content can't be decrypted
        http_response_code(400);
        echo json_encode(['error' => ['code' => 'invalid', 'message' => "Couldn't decrypt.", 'fields' => ['sealed_content' => "Couldn't decrypt."]]]);

        return true;
    }
    if ($step === 'empty') {
        http_response_code(201);
        echo '{}';

        return true;
    }
    if (is_array($step) && isset($step['delay'])) {
        usleep((int) ($step['delay'] * 1_000_000));
    } elseif (is_array($step) && isset($step[0])) {
        $err((int) $step[0], (string) ($step[1] ?? ''));

        return true;
    } elseif ($step === null && !empty($cfg['jobStatus'])) {
        $err((int) $cfg['jobStatus'], (string) ($cfg['jobCode'] ?? ''));

        return true;
    }
    http_response_code(201);
    echo json_encode(['job' => ['id' => 'cloud-1', 'status' => 'queued', 'live' => true, 'idempotency_key' => $body['idempotency_key'] ?? null]]);
} elseif ($method === 'GET' && $path === '/v1/public/server-key') {
    echo json_encode(['public_key' => base64_encode(hash('sha256', 'fake server key', true))]);
} elseif ($method === 'GET' && $path === '/v1/key') {
    if (!empty($cfg['keyStatus'])) {
        $err((int) $cfg['keyStatus'], 'internal');

        return true;
    }
    echo json_encode(['id' => 'key1', 'org_id' => 'org1', 'live' => false, 'scopes' => ['jobs:write']]);
} elseif ($method === 'GET' && $path === '/v1/orgs/org1/printers') {
    echo json_encode(['printers' => []]);
} else {
    $err(404, 'not_found');
}

return true;
