<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\Fixture;

use Componenta\Identity\IdentityInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\PermissionAwareInterface;
use Componenta\Policy\Permission\PermissionCollection;
use Componenta\Policy\Permission\PermissionCollectionInterface;

final readonly class FakeActor implements IdentityInterface, PermissionAwareInterface
{
    public PermissionCollectionInterface $permissions;
    public UuidInterface $uuid;

    public function __construct(
        private int $id,
        PermissionCollectionInterface $permissions = new PermissionCollection(),
    ) {
        $this->uuid = Uuid::fromString(sprintf('00000000-0000-7000-8000-%012d', $id));
        $this->permissions = $permissions;
    }
}
