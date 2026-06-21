<?php

declare(strict_types=1);

namespace Componenta\CQRS\Resolver;

use Componenta\Policy\ActionIdAwareInterface;

final class ActionIdResolver implements ActionIdResolverInterface
{
    public function resolve(object $subject): string
    {
        return $subject instanceof ActionIdAwareInterface
            ? $subject->actionId : $subject::class ;
    }
}
