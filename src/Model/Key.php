<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** The API key in use (GET /v1/key). Never contains the secret. */
final class Key extends Model
{
    public readonly string $id;
    public readonly string $orgId;
    public readonly string $orgName;
    /** False for cz_test_ keys. */
    public readonly bool $live;
    /** @var list<string> e.g. ["jobs:write", "jobs:read", "printers:read", "locations:read"]. */
    public readonly array $scopes;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id = self::str($data, 'id');
        $this->orgId = self::str($data, 'org_id');
        $this->orgName = self::str($data, 'org_name');
        $this->live = self::bool($data, 'live');
        $this->scopes = self::strList($data, 'scopes');
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }
}
