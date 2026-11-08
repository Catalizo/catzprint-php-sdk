<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

use CatzPrint\Exception\CatzPrintException;

/**
 * The request was written to the station completely, but no usable answer
 * came back (timeout, connection reset, read error, an answer too large or
 * not HTTP). The station may have acted on it ("DELIVERED_NO_ANSWER").
 *
 * @internal
 */
final class NoAnswerException extends CatzPrintException
{
    public static function wrap(\Throwable $e): self
    {
        $code = $e instanceof CatzPrintException && $e->errorCode !== '' ? $e->errorCode : 'connection_error';

        return new self('No answer from the station after the request was sent: ' . $e->getMessage(), 0, $code, [], null, null, $e);
    }
}
