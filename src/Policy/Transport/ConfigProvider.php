<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy\Transport;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CommandSerializerSupportInterface;
use Componenta\CQRS\Command\Transport\CompositeCommandSerializer;
use Componenta\CQRS\Policy\Transport\Factory\ActorAwareJsonCommandSerializerFactory;
use Componenta\CQRS\Policy\Transport\Factory\CompositeCommandSerializerFactory;
use LogicException;

/** Optional policy/transport integration; register explicitly when transport is installed. */
final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        if (!interface_exists(CommandSerializerSupportInterface::class)
            || !class_exists(CompositeCommandSerializer::class)
        ) {
            throw new LogicException(
                'CQRS policy transport integration requires componenta/cqrs-transport 3.1 or newer.',
            );
        }

        return [
            ActorAwareJsonCommandSerializer::class => ActorAwareJsonCommandSerializerFactory::class,
            CommandSerializerInterface::class => CompositeCommandSerializerFactory::class,
        ];
    }
}
