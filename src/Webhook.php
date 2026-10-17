<?php

declare(strict_types=1);

namespace CatzPrint;

use CatzPrint\Exception\SignatureVerificationException;
use CatzPrint\Model\Event;

/**
 * Verifies CatzPrint webhook signatures.
 *
 * Every delivery carries
 *   CatzPrint-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, t + "." + raw body)>
 * Verify against the RAW request body — re-encoding the JSON changes the bytes.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'CatzPrint-Signature';
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Checks the signature and returns the parsed event.
     *
     * @param string      $payload         The raw request body.
     * @param string|null $signatureHeader The CatzPrint-Signature header value.
     * @param string      $secret          The endpoint's signing secret (whsec_…).
     * @param int         $tolerance       Max age (and clock skew) in seconds; 0 disables the check.
     * @param int|null    $now             Current unix time (for tests).
     *
     * @throws SignatureVerificationException
     */
    public static function verify(
        string $payload,
        ?string $signatureHeader,
        #[\SensitiveParameter] string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): Event {
        if ($secret === '') {
            throw new SignatureVerificationException('No webhook secret given.', 0, 'no_secret');
        }
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw new SignatureVerificationException('Missing CatzPrint-Signature header.', 0, 'no_signature');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$k, $v] = [trim($kv[0]), trim($kv[1])];
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1' && $v !== '') {
                $signatures[] = strtolower($v);
            }
        }
        if ($timestamp === null || !preg_match('/^\d{1,12}$/', $timestamp)) {
            throw new SignatureVerificationException('CatzPrint-Signature has no valid timestamp.', 0, 'bad_signature_header');
        }
        if ($signatures === []) {
            throw new SignatureVerificationException('CatzPrint-Signature has no v1 signature.', 0, 'bad_signature_header');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $match = false;
        foreach ($signatures as $sig) {
            // hash_equals is constant-time; check them all so timing doesn't reveal which matched.
            $match = hash_equals($expected, $sig) || $match;
        }
        if (!$match) {
            throw new SignatureVerificationException('Webhook signature does not match the payload.', 0, 'signature_mismatch');
        }

        $now ??= time();
        if ($tolerance > 0 && abs($now - (int) $timestamp) > $tolerance) {
            throw new SignatureVerificationException(
                sprintf('Webhook timestamp is outside the %d s tolerance.', $tolerance),
                0,
                'timestamp_out_of_tolerance',
            );
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            throw new SignatureVerificationException('Webhook payload is not a JSON object.', 0, 'invalid_payload');
        }

        return new Event($data);
    }

    /**
     * Alias of verify(), named like Stripe's Webhook::constructEvent().
     *
     * @throws SignatureVerificationException
     */
    public static function constructEvent(
        string $payload,
        ?string $signatureHeader,
        #[\SensitiveParameter] string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): Event {
        return self::verify($payload, $signatureHeader, $secret, $tolerance, $now);
    }

    /**
     * Builds a header exactly like the server does (core.SignWebhook). Handy
     * for testing your own webhook endpoint.
     */
    public static function sign(string $payload, #[\SensitiveParameter] string $secret, ?int $timestamp = null): string
    {
        $t = (string) ($timestamp ?? time());

        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret);
    }
}
