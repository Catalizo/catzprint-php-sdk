<?php

declare(strict_types=1);

namespace CatzPrint\Resource;

use CatzPrint\Client;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\HttpClient;
use CatzPrint\Model\LocalToken;

/**
 * Mints and revokes local tokens. A local token ("czl1…") lets a device print
 * through any station of the organization on its local network, verified by
 * the station without the internet. With the "local" client option the SDK
 * mints, caches and refreshes tokens for you.
 */
final class LocalTokens
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Mints a local token (POST /v1/orgs/{org}/local-tokens). Needs an API
     * key with the jobs:write scope.
     *
     * @param array{deviceName: string, locationIds?: list<string>, ttlDays?: int} $params
     *   - deviceName:  identifies the device on the station (up to 80 characters)
     *   - locationIds: narrows the token to these locations (default: every location the key may use)
     *   - ttlDays:     lifetime, 1..30 (default 30)
     */
    public function create(array $params): LocalToken
    {
        return $this->createWith($this->client->http(), $params);
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $params
     */
    public function createWith(HttpClient $http, array $params): LocalToken
    {
        $unknown = array_diff(array_keys($params), ['deviceName', 'locationIds', 'ttlDays']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown local token parameter(s): ' . implode(', ', $unknown) . '.');
        }
        $name = trim((string) ($params['deviceName'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Set deviceName.', 0, 'invalid', ['device_name' => 'Set deviceName.']);
        }
        $ttl = (int) ($params['ttlDays'] ?? 0);
        if ($ttl < 0 || $ttl > 30) {
            throw new ValidationException('ttlDays must be between 1 and 30.', 0, 'invalid', ['ttl_days' => 'ttlDays must be between 1 and 30.']);
        }
        $body = ['device_name' => $name];
        if (!empty($params['locationIds'])) {
            $body['location_ids'] = array_values(array_map('strval', (array) $params['locationIds']));
        }
        if ($ttl > 0) {
            $body['ttl_days'] = $ttl;
        }
        $res = $http->request('POST', $this->client->orgPath('/local-tokens'), [], $body);
        $tok = new LocalToken($res);
        if ($tok->token === '' || preg_match('/^czl1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $tok->token) !== 1) {
            throw new CatzPrintException('The API answered without a token.', 201, 'invalid_response');
        }

        return $tok;
    }

    /** Revokes a local token by its token ID (the jti). Stations refuse it once they have synced. */
    public function revoke(string $tokenId): void
    {
        $this->client->http()->request('DELETE', $this->client->orgPath('/local-tokens/' . rawurlencode($tokenId)));
    }
}
