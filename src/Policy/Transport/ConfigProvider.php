<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Policy\Transport\Factory\ActorAwareJsonCommandSerializerFactory;
use Componenta\CQRS\Policy\Transport\Factory\CompositeCommandSerializerFactory;

/** Optional policy/transport integration; register explicitly when transport is installed. */
final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            ActorAwareJsonCommandSerializer::class => ActorAwareJsonCommandSerializerFactory::class,
            CommandSerializerInterface::class => CompositeCommandSerializerFactory::class,
        ];
    }
}
