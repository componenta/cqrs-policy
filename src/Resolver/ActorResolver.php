<?php

declare(strict_types=1);

namespace Componenta\CQRS\Resolver;

use Componenta\Policy\Actor\ActorAwareInterface;

/** Extracts the actor explicitly carried by a CQRS message. */
final readonly class ActorResolver
{
    public function resolve(object $message): ?object
    {
        return $message instanceof ActorAwareInterface
            ? $message->actor
            : null;
    }
}
