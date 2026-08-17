<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\CompositeCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\CQRS\Policy\Transport\ConfigProvider;
use Componenta\CQRS\Policy\Transport\Factory\ActorAwareJsonCommandSerializerFactory;
use Componenta\CQRS\Policy\Transport\Factory\CompositeCommandSerializerFactory;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\Identity\UuidInterface;

it('registers specialized and composite serializer factories only through the explicit transport provider', function (): void {
    $config = (new ConfigProvider())();
    $factories = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES];

    expect($factories)->toBe([
        ActorAwareJsonCommandSerializer::class => ActorAwareJsonCommandSerializerFactory::class,
        CommandSerializerInterface::class => CompositeCommandSerializerFactory::class,
    ]);
});

it('builds the specialized serializer from ContainerValue', function (): void {
    $repository = new class implements ActorRepositoryInterface {
        public function findByUuid(UuidInterface $uuid): ?object
        {
            return null;
        }
    };
    $container = new ContainerValue(
        new FakeContainer([
            ActorRepositoryInterface::class => $repository,
        ]),
        new Config([]),
    );

    $serializer = (new ActorAwareJsonCommandSerializerFactory())($container);

    expect($serializer)->toBeInstanceOf(ActorAwareJsonCommandSerializer::class);
});

it('builds the default ordered composite with actor-aware serializer before JSON fallback', function (): void {
    $repository = new class implements ActorRepositoryInterface {
        public function findByUuid(UuidInterface $uuid): ?object
        {
            return null;
        }
    };
    $actorAware = new ActorAwareJsonCommandSerializer($repository);
    $container = new ContainerValue(
        new FakeContainer([
            ActorAwareJsonCommandSerializer::class => $actorAware,
        ]),
        new Config([]),
    );

    $serializer = (new CompositeCommandSerializerFactory())($container);

    expect($serializer)->toBeInstanceOf(CompositeCommandSerializer::class);
});
