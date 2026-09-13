<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistry;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\CQRS\Transport\Attribute\Async;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;

class ActorNativeStorageBase extends SplObjectStorage
{
}

#[Async]
final class ActorNativeStorageCommand extends ActorNativeStorageBase implements ActorAwareInterface
{
    public static int $constructions = 0;

    public function __construct(public object $actor, public int $id)
    {
        ++self::$constructions;
    }
}

it('rejects inherited native state before sending an actor-aware command', function (): void {
    ActorNativeStorageCommand::$constructions = 0;
    $command = new ActorNativeStorageCommand(new Guest(), 7);
    $command[new stdClass()] = 'required-item';
    $transport = $this->createMock(TransportInterface::class);
    $transport->expects($this->never())->method('send');
    $handler = $this->createMock(OperationHandlerInterface::class);
    $handler->expects($this->never())->method('handle');
    $registry = new TransportRegistry();
    $registry->register('default', $transport);
    $middleware = new TransportMiddleware(
        $registry,
        new ActorAwareJsonCommandSerializer($this->createStub(ActorRepositoryInterface::class)),
        new ReflectionCommandMetadataProvider(),
        new JsonOperationContextSerializer(),
    );

    expect(fn () => $middleware->execute(Operation::create($command), $handler))
        ->toThrow(TransportException::class, 'SplObjectStorage');
    expect($command)->toHaveCount(1)
        ->and(ActorNativeStorageCommand::$constructions)->toBe(1);
});

it('rejects actor-aware reconstruction of native state before invoking the constructor or repository', function (): void {
    ActorNativeStorageCommand::$constructions = 0;
    $repository = $this->createMock(ActorRepositoryInterface::class);
    $repository->expects($this->never())->method('findByUuid');
    $serializer = new ActorAwareJsonCommandSerializer($repository);
    $payload = '{"__componenta_cqrs":2,"data":{"actor":{"type":"identity","uuid":"00000000-0000-4000-8000-000000000007"},"id":7}}';

    expect(fn () => $serializer->deserialize($payload, ActorNativeStorageCommand::class))
        ->toThrow(TransportException::class, 'SplObjectStorage');
    expect(ActorNativeStorageCommand::$constructions)->toBe(0);
});

class ActorStoredStateBase extends stdClass implements ActorAwareInterface
{
    public function __construct(public object $actor, public int $id)
    {
    }
}

final class ActorStoredStateChild extends ActorStoredStateBase
{
}

it('preserves public actor-aware state inherited through a stdClass ancestor', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer($this->createStub(ActorRepositoryInterface::class));
    $payload = $serializer->serialize(new ActorStoredStateChild(new Guest(), 7));
    $restored = $serializer->deserialize($payload, ActorStoredStateChild::class);

    expect($restored)->toBeInstanceOf(ActorStoredStateChild::class)
        ->and($restored->actor)->toBeInstanceOf(Guest::class)
        ->and($restored->id)->toBe(7);
});
