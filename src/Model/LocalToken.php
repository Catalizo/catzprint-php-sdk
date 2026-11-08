<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** A minted local token ("czl1…") that stations verify offline. */
final class LocalToken extends Model
{
    public readonly string $token;
    /** The token's id (jti), for LocalTokens::revoke(). */
    public readonly string $tokenId;
    public readonly ?\DateTimeImmutable $expiresAt;
    /** @var list<LocalEndpoint>|null The endpoint list sent with the token (null when absent). */
    public readonly ?array $stations;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->token = self::str($data, 'token');
        $this->tokenId = self::str($data, 'token_id');
        $this->expiresAt = self::date($data, 'expires_at');
        $this->stations = array_key_exists('stations', $data) && is_array($data['stations'])
            ? LocalEndpoint::listFrom($data['stations'])
            : null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['token' => substr($this->token, 0, 5) . '…', 'tokenId' => $this->tokenId, 'expiresAt' => $this->expiresAt];
    }
}
