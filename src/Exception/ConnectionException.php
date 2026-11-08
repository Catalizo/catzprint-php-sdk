<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** The request never got an HTTP answer (DNS, TLS, refused, timed out). */
class ConnectionException extends CatzPrintException
{
    private bool $delivered = false;

    /**
     * True when the whole request had been sent before the failure (e.g. a
     * timeout waiting for the answer): the server may have acted on it.
     */
    public function requestDelivered(): bool
    {
        return $this->delivered;
    }

    /** @internal */
    public function markDelivered(): static
    {
        $this->delivered = true;

        return $this;
    }
}
