<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport\Factory;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;

final class ActorAwareJsonCommandSerializerFactory
{
    public function __invoke(ContainerValue $container): ActorAwareJsonCommandSerializer
    {
        return new ActorAwareJsonCommandSerializer(
            $container->get(
                ActorRepositoryInterface::class,
                ActorRepositoryInterface::class,
            ),
        );
    }
}
