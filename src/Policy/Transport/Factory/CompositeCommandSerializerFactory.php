<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport\Factory;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CompositeCommandSerializer;
use Componenta\CQRS\Command\Transport\JsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;

final class CompositeCommandSerializerFactory
{
    public function __invoke(ContainerValue $container): CommandSerializerInterface
    {
        return new CompositeCommandSerializer([
            $container->get(
                ActorAwareJsonCommandSerializer::class,
                ActorAwareJsonCommandSerializer::class,
            ),
            new JsonCommandSerializer(),
        ]);
    }
}
