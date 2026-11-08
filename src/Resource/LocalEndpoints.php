<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Client;
use CatzPrint\Internal\HttpClient;
use CatzPrint\Model\LocalEndpoint;

/** The local-network addresses of the organization's stations. */
final class LocalEndpoints
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The stations' local endpoints (GET /v1/orgs/{org}/local-endpoints),
     * optionally only those at one location.
     *
     * @return list<LocalEndpoint>
     */
    public function list(?string $locationId = null): array
    {
        return $this->listWith($this->client->http(), (string) $locationId);
    }

    /**
     * @internal
     *
     * @return list<LocalEndpoint>
     */
    public function listWith(HttpClient $http, string $locationId): array
    {
        $res = $http->request('GET', $this->client->orgPath('/local-endpoints'), $locationId !== '' ? ['location_id' => $locationId] : []);

        return LocalEndpoint::listFrom($res['stations'] ?? []);
    }
}
