<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** A webhook's CatzPrint-Signature header is missing, malformed, stale or wrong. */
class SignatureVerificationException extends CatzPrintException
{
}
