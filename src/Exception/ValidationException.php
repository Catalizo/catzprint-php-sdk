<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** 400/422: the request was rejected; see $fields for per-field messages. */
class ValidationException extends CatzPrintException
{
}
