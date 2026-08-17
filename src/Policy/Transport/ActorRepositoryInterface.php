<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport;

use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorInterface;

/** Loads the current actor state used to restore transported commands. */
interface ActorRepositoryInterface
{
    public function findByUuid(UuidInterface $uuid): ?ActorInterface;
}
