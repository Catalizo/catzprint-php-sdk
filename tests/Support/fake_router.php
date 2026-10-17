<?php

declare(strict_types=1);

/*
 * Router for `php -S` used by the unit tests as a scriptable fake API.
 *
 *   POST /__queue     body: list of {status, headers?, json?|body?, delay?} appended to the response queue
 *   GET  /__requests  every request recorded so far
 *   POST /__reset     clears queue and log
 *
 * Any other request is recorded and answered with the next queued response
 * (or 500 when the queue is empty). State lives in a JSON file (FAKE_STATE).
 */

$stateFile = getenv('FAKE_STATE') ?: sys_get_temp_dir() . '/catzprint-fake-state.json';
$load = static function () use ($stateFile): array {
    $s = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;

    return is_array($s) ? $s : ['queue' => [], 'requests' => []];
};
$save = static function (array $s) use ($stateFile): void {
    file_put_contents($stateFile, json_encode($s), LOCK_EX);
};

$method = $_SERVER['REQUEST_METHOD'];
$uri = (string) $_SERVER['REQUEST_URI'];
$path = (string) parse_url($uri, PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

$state = $load();
header('Content-Type: application/json');

if ($path === '/__queue') {
    foreach ((array) json_decode($body, true) as $r) {
        $state['queue'][] = $r;
    }
    $save($state);
    echo '{}';

    return true;
}
if ($path === '/__requests') {
    echo json_encode($state['requests']);

    return true;
}
if ($path === '/__reset') {
    $save(['queue' => [], 'requests' => []]);
    echo '{}';

    return true;
}

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
$state['requests'][] = [
    'method' => $method,
    'path' => $path,
    'query' => (string) parse_url($uri, PHP_URL_QUERY),
    'headers' => $headers,
    'body' => $body,
];
$next = array_shift($state['queue']);
$save($state);

if ($next === null) {
    http_response_code(500);
    echo json_encode(['error' => ['code' => 'fake_empty', 'message' => "No scripted response for $method $path"]]);

    return true;
}
if (!empty($next['delay'])) {
    usleep((int) ($next['delay'] * 1_000_000));
}
http_response_code((int) ($next['status'] ?? 200));
foreach ((array) ($next['headers'] ?? []) as $k => $v) {
    header("$k: $v");
}
if (array_key_exists('json', $next)) {
    echo json_encode($next['json']);
} else {
    echo (string) ($next['body'] ?? '');
}

return true;
