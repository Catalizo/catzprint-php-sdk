<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** Jobs::waitFor() gave up before the job reached a final status. */
class TimeoutException extends CatzPrintException
{
}
