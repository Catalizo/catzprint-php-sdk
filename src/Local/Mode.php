<?php

declare(strict_types=1);

namespace CatzPrint\Local;

/** Where Jobs::create() sends jobs (the "mode" local option). */
final class Mode
{
    /** Every job goes to the CatzPrint API (the default; unchanged behaviour). */
    public const CLOUD = 'cloud';
    /** Jobs go only to stations on the local network. */
    public const LOCAL = 'local';
    /** Stations first; the cloud only when no station can be reached. */
    public const LOCAL_FIRST = 'local_first';
    /** The cloud first; stations on a network error, timeout or 5xx. */
    public const CLOUD_FIRST = 'cloud_first';

    public const ALL = [self::CLOUD, self::LOCAL, self::LOCAL_FIRST, self::CLOUD_FIRST];

    private function __construct()
    {
    }
}
