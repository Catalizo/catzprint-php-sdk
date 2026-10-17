<?php

declare(strict_types=1);

/*
 * A plain-PHP webhook endpoint.
 *
 * Serve it:   CATZ_WEBHOOK_SECRET=whsec_… php -S 127.0.0.1:8000 examples/verify_webhook.php
 * Or run it from the command line for a self-contained demo:
 *             php examples/verify_webhook.php
 */

require __DIR__ . '/autoload.php';

use CatzPrint\Exception\SignatureVerificationException;
use CatzPrint\Model\Event;
use CatzPrint\Webhook;

function handle(Event $event): void
{
    switch ($event->type) {
        case 'job.printed':
            error_log('Printed: ' . ($event->job()?->reference ?? '?'));
            break;
        case 'job.failed':
            error_log('Failed: ' . ($event->job()?->statusDetail ?? '?'));
            break;
        default:
            error_log('Event: ' . $event->type);
    }
}

if (PHP_SAPI === 'cli') {
    // Demo: sign a sample event like the server does, then verify it.
    $secret = 'whsec_demo_secret';
    $body = '{"created_at":"2026-10-12T09:14:03Z","data":{"job":{"id":"6f1c2a7e","status":"printed","reference":"order-1042"}},"org_id":"9b2f","type":"job.printed"}';
    $header = Webhook::sign($body, $secret);
    $event = Webhook::verify($body, $header, $secret);
    printf("Verified %s for %s\n", $event->type, $event->job()?->reference);
    try {
        Webhook::verify(str_replace('1042', '1043', $body), $header, $secret);
    } catch (SignatureVerificationException $e) {
        printf("Tampered body rejected: %s\n", $e->getMessage());
    }
    exit(0);
}

// HTTP: verify the RAW body, never a re-encoded one.
$payload = (string) file_get_contents('php://input');
try {
    $event = Webhook::verify($payload, $_SERVER['HTTP_CATZPRINT_SIGNATURE'] ?? null, (string) getenv('CATZ_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    echo 'bad signature';
    exit;
}
handle($event); // do slow work in a queue; answer quickly
http_response_code(204);
