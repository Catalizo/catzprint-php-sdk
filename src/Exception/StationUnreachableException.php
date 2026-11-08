<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/**
 * No CatzPrint station on the local network could take the request: none
 * answered, the TLS check or certificate pin failed, the request timed out,
 * a station answered 502/504 or 503 local_disabled (or 503 without a code),
 * or no local token could be obtained because the cloud is unreachable.
 *
 * In the fallback modes (local_first, cloud_first) $cloudError holds the
 * cloud's error when the cloud failed too; it is also getPrevious().
 */
class StationUnreachableException extends CatzPrintException
{
    /**
     * @param list<StationAttempt> $attempts
     */
    public function __construct(
        public readonly array $attempts = [],
        public readonly ?\Throwable $cloudError = null,
    ) {
        parent::__construct(self::describe($attempts, $cloudError), 0, 'station_unreachable', [], null, null, $cloudError);
    }

    /**
     * The station URLs that were tried, in order.
     *
     * @return list<string>
     */
    public function urls(): array
    {
        $out = [];
        foreach ($this->attempts as $a) {
            if ($a->url !== '') {
                $out[] = $a->url;
            }
        }

        return $out;
    }

    /** @internal A copy that also carries the cloud's error. */
    public function withCloudError(\Throwable $cloudError): self
    {
        return new self($this->attempts, $cloudError);
    }

    /** @param list<StationAttempt> $attempts */
    private static function describe(array $attempts, ?\Throwable $cloudError): string
    {
        $msg = 'No CatzPrint station could be reached';
        if ($attempts === []) {
            $msg .= ' (no station address known: set stationUrls or discover)';
        }
        foreach ($attempts as $i => $a) {
            $msg .= $i === 0 ? ': ' : '; ';
            if ($a->url !== '') {
                $msg .= $a->url . ': ';
            }
            $msg .= rtrim($a->error->getMessage(), '.');
        }
        if ($cloudError !== null) {
            $msg .= '; cloud fallback failed too: ' . rtrim($cloudError->getMessage(), '.');
        }

        return $msg . '.';
    }
}
