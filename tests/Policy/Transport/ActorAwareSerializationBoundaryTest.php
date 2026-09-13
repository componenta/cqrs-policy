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

#[Async]
final readonly class ActorSerializationPrivateConstructorCommand implements ActorAwareInterface
{
    private function __construct(public object $actor, public int $id)
    {
    }

    public static function create(): self
    {
        return new self(new Guest(), 7);
    }
}

#[Async]
final readonly class ActorSerializationConvertedStateCommand implements ActorAwareInterface
{
    public string $id;

    public function __construct(public object $actor, int $id)
    {
        $this->id = (string) $id;
    }
}

it('rejects unrestorable constructor state before sending actor-aware commands', function (Closure $command, string $error): void {
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
    $operation = Operation::create($command());

    expect(fn () => $middleware->execute($operation, $handler))
        ->toThrow(TransportException::class, $error);
})->with([
    'private constructor' => [
        static fn () => ActorSerializationPrivateConstructorCommand::create(),
        'must be instantiable',
    ],
    'stored value incompatible with constructor' => [
        static fn () => new ActorSerializationConvertedStateCommand(new Guest(), 7),
        'must match int; string given',
    ],
]);


final class ActorSerializationConstructionProbe implements ActorAwareInterface
{
    public static int $constructions = 0;

    public function __construct(public Guest $actor, public int $id)
    {
        ++self::$constructions;
    }
}

it('serializes a typed actor without constructing the command or querying the repository', function (): void {
    ActorSerializationConstructionProbe::$constructions = 0;
    $command = new ActorSerializationConstructionProbe(new Guest(), 7);
    $repository = $this->createMock(ActorRepositoryInterface::class);
    $repository->expects($this->never())->method('findByUuid');
    $serializer = new ActorAwareJsonCommandSerializer($repository);

    $payload = $serializer->serialize($command);

    expect(ActorSerializationConstructionProbe::$constructions)->toBe(1)
        ->and(json_decode($payload, true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['__componenta_cqrs' => 2, 'data' => ['actor' => ['type' => 'guest'], 'id' => 7]]);

    $restored = $serializer->deserialize($payload, ActorSerializationConstructionProbe::class);

    expect($restored)->toBeInstanceOf(ActorSerializationConstructionProbe::class)
        ->and($restored->actor)->toBeInstanceOf(Guest::class)
        ->and($restored->id)->toBe(7)
        ->and(ActorSerializationConstructionProbe::$constructions)->toBe(2);
});
