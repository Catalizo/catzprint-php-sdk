<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Client;
use CatzPrint\Model\Printer;

/** Printers in the organization (needs the printers:read scope). */
final class Printers
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array{stationId?: string} $params
     *
     * @return list<Printer>
     */
    public function list(array $params = []): array
    {
        $query = isset($params['stationId']) ? ['station_id' => $params['stationId']] : [];
        $res = $this->client->http()->request('GET', $this->client->orgPath('/printers'), $query);
        $out = [];
        foreach (is_array($res['printers'] ?? null) ? $res['printers'] : [] as $p) {
            if (is_array($p)) {
                $out[] = new Printer($p);
            }
        }

        return $out;
    }
}
