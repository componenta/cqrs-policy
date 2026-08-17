<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport\Factory;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;

final class ActorAwareJsonCommandSerializerFactory
{
    public function __invoke(ContainerValue $container): CommandSerializerInterface
    {
        return new ActorAwareJsonCommandSerializer(
            actors: $container->get(
                ActorRepositoryInterface::class,
                ActorRepositoryInterface::class,
            ),
            fallback: new JsonCommandSerializer(),
        );
    }
}
