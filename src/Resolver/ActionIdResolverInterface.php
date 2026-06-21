<?php

declare(strict_types=1);

namespace Componenta\CQRS\Resolver;

interface ActionIdResolverInterface
{
    public function resolve(object $subject): string ;
}
