<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/**
 * 503 from a CatzPrint station whose printer can't print right now: error
 * code printer_offline, out_of_paper, cover_open or printer_error.
 *
 * The station did not accept the job (it is not recorded or counted). Fix
 * the printer and send it again with the same idempotency key. The SDK never
 * falls back to the cloud for these.
 */
class PrinterException extends CatzPrintException
{
    /** The error codes a station uses for printer problems. */
    public const CODES = ['printer_offline', 'out_of_paper', 'cover_open', 'printer_error'];
}
