<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport;

use Componenta\Identity\UuidInterface;

/** Loads the current policy actor used to restore transported commands. */
interface ActorRepositoryInterface
{
    public function findByUuid(UuidInterface $uuid): ?object;
}
