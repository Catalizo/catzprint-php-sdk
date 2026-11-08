<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Client;
use CatzPrint\Model\Job;
use CatzPrint\Model\LocalInfo;
use CatzPrint\Model\LocalPairing;
use CatzPrint\Model\LocalStation;
use CatzPrint\Model\LocalStatus;

/**
 * Talks to CatzPrint stations on the local network: status, discovery,
 * pairing and local jobs. Configure it with the "local" client option.
 */
final class Local
{
    public function __construct(private readonly Client $client)
    {
    }

    /** The configured mode: cloud, local, local_first or cloud_first. */
    public function mode(): string
    {
        return $this->client->localState()->cfg->mode;
    }

    /**
     * The status of the first station that answers (printers, paper,
     * unsynced jobs, offline allowance).
     */
    public function status(): LocalStatus
    {
        return $this->client->localState()->status();
    }

    /**
     * Stations from the cloud endpoint list (refreshed now when the cloud
     * answers, otherwise the cached copy) and, with ext-sockets, a short mDNS
     * browse, merged by station ID. It doesn't need the "discover" option.
     *
     * @return list<LocalStation>
     */
    public function discover(): array
    {
        return $this->client->localState()->discover();
    }

    /**
     * A station's public information (GET /local/v1/info, no token needed).
     * The URL must be trusted like any station URL: a public hostname from
     * the cloud endpoint list, or an IP with a fingerprint from the endpoint
     * list or the "fingerprints" option.
     */
    public function info(string $stationUrl): LocalInfo
    {
        return $this->client->localState()->info($stationUrl);
    }

    /**
     * Pairs this device with one station using the 8-digit code shown in the
     * station app ("Pair a device"). The returned device token ("czd_…")
     * works only on that station and without any cloud account; pass it as
     * the "token" local option.
     *
     * The station must be authenticated before the code is sent: pass the
     * certificate fingerprint shown in the station app next to the code
     * (with an IP URL), or pair with a station on the cloud endpoint list.
     * Without either the SDK refuses (ValidationException code "invalid").
     */
    public function pair(string $stationUrl, #[\SensitiveParameter] string $code, ?string $deviceName = null, ?string $fingerprint = null): LocalPairing
    {
        return $this->client->localState()->pair($stationUrl, $code, $deviceName, $fingerprint);
    }

    /**
     * A local job ("lj_…") from the station that has it. Jobs::get() and
     * Jobs::waitFor() call this for local job IDs.
     */
    public function getJob(string $id): Job
    {
        return $this->client->localState()->getJob($id);
    }

    /**
     * Renews the local token (when it has less than 7 days left) and
     * re-reads the cloud endpoint list now. The SDK also does this by itself,
     * lazily; call it from a cron job if you turned "autoRefresh" off.
     */
    public function refresh(): void
    {
        $this->client->localState()->refresh(true);
    }

    /**
     * The token the SDK uses for stations (minting one when needed), e.g. to
     * hand to a browser POS that uses catzprint/local.
     */
    public function token(): string
    {
        return $this->client->localState()->token();
    }

    /**
     * The last error from the token store (null if none). Store failures
     * never stop printing; they only mean the token or endpoint list won't
     * survive to the next request.
     */
    public function storeError(): ?\Throwable
    {
        return $this->client->localState()->storeError();
    }

    /** The last error of a lazy refresh (null when the last one worked). */
    public function refreshError(): ?\Throwable
    {
        return $this->client->localState()->refreshError();
    }
}
