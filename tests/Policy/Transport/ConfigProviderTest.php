<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\Config\ContainerValue;
use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\CQRS\Policy\Transport\ConfigProvider;
use Componenta\CQRS\Policy\Transport\Factory\ActorAwareJsonCommandSerializerFactory;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorInterface;

it('registers the actor-aware serializer only through the explicit transport provider', function (): void {
    $config = (new ConfigProvider())();
    $factories = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES];

    expect($factories)->toBe([
        CommandSerializerInterface::class => ActorAwareJsonCommandSerializerFactory::class,
    ]);
});

it('builds the actor-aware serializer from ContainerValue', function (): void {
    $repository = new class implements ActorRepositoryInterface {
        public function findByUuid(UuidInterface $uuid): ?ActorInterface
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
