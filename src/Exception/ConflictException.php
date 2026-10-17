<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** 409: e.g. a job that can't change state, a disabled printer or an idempotency clash. */
class ConflictException extends CatzPrintException
{
}
