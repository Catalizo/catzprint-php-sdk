<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Model\Event;
use CatzPrint\Webhook;

/**
 * $client->webhooks — instance access to Webhook::verify(). Verifying needs
 * no API key; you can also call CatzPrint\Webhook::verify() statically.
 */
final class Webhooks
{
    public function verify(string $payload, ?string $signatureHeader, string $secret, int $tolerance = Webhook::DEFAULT_TOLERANCE): Event
    {
        return Webhook::verify($payload, $signatureHeader, $secret, $tolerance);
    }

    public function constructEvent(string $payload, ?string $signatureHeader, string $secret, int $tolerance = Webhook::DEFAULT_TOLERANCE): Event
    {
        return Webhook::constructEvent($payload, $signatureHeader, $secret, $tolerance);
    }
}
