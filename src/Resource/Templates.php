<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Client;
use CatzPrint\Model\Template;

/** Receipt templates (needs the jobs:write scope). */
final class Templates
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return list<Template> */
    public function list(): array
    {
        $res = $this->client->http()->request('GET', $this->client->orgPath('/templates'));
        $out = [];
        foreach (is_array($res['templates'] ?? null) ? $res['templates'] : [] as $t) {
            if (is_array($t)) {
                $out[] = new Template($t);
            }
        }

        return $out;
    }
}
