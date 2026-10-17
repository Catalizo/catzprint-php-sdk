<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Client;
use CatzPrint\Model\Location;

/** Locations in the organization (needs the locations:read scope). */
final class Locations
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return list<Location> */
    public function list(): array
    {
        $res = $this->client->http()->request('GET', $this->client->orgPath('/locations'));
        $out = [];
        foreach (is_array($res['locations'] ?? null) ? $res['locations'] : [] as $l) {
            if (is_array($l)) {
                $out[] = new Location($l);
            }
        }

        return $out;
    }

    public function get(string $id): Location
    {
        $res = $this->client->http()->request('GET', $this->client->orgPath('/locations/' . rawurlencode($id)));

        return new Location(is_array($res['location'] ?? null) ? $res['location'] : $res);
    }
}
