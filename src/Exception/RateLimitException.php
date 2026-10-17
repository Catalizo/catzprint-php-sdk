<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** 429: too many requests; $retryAfter says how long to wait. */
class RateLimitException extends CatzPrintException
{
}
