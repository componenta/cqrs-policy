<?php

declare(strict_types=1);

namespace Componenta\CQRS\Resolver;

use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorInterface;

/** Extracts the actor explicitly carried by a CQRS message. */
final readonly class ActorResolver
{
    public function resolve(object $message): ?ActorInterface
    {
        return $message instanceof ActorAwareInterface
            ? $message->actor
            : null;
    }
}
