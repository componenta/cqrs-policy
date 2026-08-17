<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorInterface;

final readonly class PolicyTransportActorCommand implements ActorAwareInterface
{
    /** @param list<string> $tags */
    public function __construct(
        public ActorInterface $actor,
        public int $id,
        public array $tags = [],
    ) {}
}

final readonly class PolicyTransportDefaultActorCommand implements ActorAwareInterface
{
    public function __construct(
        public ActorInterface $actor = new FakeActor(9),
    ) {}
}

final class PolicyTransportActorReplacingCommand implements ActorAwareInterface
{
    public ActorInterface $actor;

    public function __construct(ActorInterface $actor)
    {
        $this->actor = new FakeActor(2);
    }
}

final readonly class PolicyTransportAnonymousCommand
{
    public function __construct(public int $id) {}
}

final class PolicyTransportActorRepository implements ActorRepositoryInterface
{
    /** @var list<string> */
    public array $requested = [];

    public function __construct(public ?ActorInterface $actor) {}

    public function findByUuid(UuidInterface $uuid): ?ActorInterface
    {
        $this->requested[] = $uuid->toString();

        return $this->actor;
    }
}

final class PolicyTransportFallbackSerializer implements CommandSerializerInterface
{
    /** @var list<object> */
    public array $serialized = [];

    /** @var list<array{string, string}> */
    public array $deserialized = [];

    public function __construct(public object $result) {}

    public function serialize(object $command): string
    {
        $this->serialized[] = $command;

        return 'fallback-payload';
    }

    public function deserialize(string $payload, string $commandClass): object
    {
        $this->deserialized[] = [$payload, $commandClass];

        return $this->result;
    }
}

it('serializes actor-aware commands by UUID and restores the current actor from repository', function (): void {
    $originalActor = new FakeActor(1);
    $currentActor = new FakeActor(1);
    $repository = new PolicyTransportActorRepository($currentActor);
    $serializer = new ActorAwareJsonCommandSerializer($repository);
    $command = new PolicyTransportActorCommand($originalActor, 42, ['one', 'two']);

    $payload = $serializer->serialize($command);
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($payload, PolicyTransportActorCommand::class);

    expect($decoded)->toBe([
        '__componenta_cqrs' => 1,
        'data' => [
            'actor' => $originalActor->uuid->toString(),
            'id' => 42,
            'tags' => ['one', 'two'],
        ],
    ])->and($restored)->toBeInstanceOf(PolicyTransportActorCommand::class)
        ->and($restored->actor)->toBe($currentActor)
        ->and($restored->id)->toBe(42)
        ->and($restored->tags)->toBe(['one', 'two'])
        ->and($repository->requested)->toBe([$originalActor->uuid->toString()]);
});

it('delegates non-actor-aware commands to the standard serializer path', function (): void {
    $result = new PolicyTransportAnonymousCommand(9);
    $fallback = new PolicyTransportFallbackSerializer($result);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository(null),
        $fallback,
    );
    $command = new PolicyTransportAnonymousCommand(7);

    expect($serializer->serialize($command))->toBe('fallback-payload')
        ->and($serializer->deserialize('encoded', PolicyTransportAnonymousCommand::class))->toBe($result)
        ->and($fallback->serialized)->toBe([$command])
        ->and($fallback->deserialized)->toBe([
            ['encoded', PolicyTransportAnonymousCommand::class],
        ]);
});

it('fails closed when the transported actor no longer exists', function (): void {
    $actor = new FakeActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository(null),
    );
    $payload = json_encode([
        '__componenta_cqrs' => 1,
        'data' => [
            'actor' => $actor->uuid->toString(),
            'id' => 42,
            'tags' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => $serializer->deserialize($payload, PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, 'was not found');
});

it('requires an actor UUID even when the command constructor has a default actor', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository(new FakeActor(9)),
    );
    $payload = json_encode([
        '__componenta_cqrs' => 1,
        'data' => [],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => $serializer->deserialize($payload, PolicyTransportDefaultActorCommand::class))
        ->toThrow(TransportException::class, 'missing its actor UUID');
});

it('rejects a constructor that replaces the restored actor identity', function (): void {
    $actor = new FakeActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $payload = json_encode([
        '__componenta_cqrs' => 1,
        'data' => [
            'actor' => $actor->uuid->toString(),
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => $serializer->deserialize($payload, PolicyTransportActorReplacingCommand::class))
        ->toThrow(TransportException::class, 'replaced transported actor');
});

it('rejects an actor returned for a different UUID', function (): void {
    $requested = new FakeActor(1);
    $different = new FakeActor(2);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($different),
    );
    $payload = json_encode([
        '__componenta_cqrs' => 1,
        'data' => [
            'actor' => $requested->uuid->toString(),
            'id' => 42,
            'tags' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => $serializer->deserialize($payload, PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, 'different identity');
});

it('rejects malformed actor UUIDs and unknown command fields', function (array $data, string $message): void {
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository(new FakeActor(1)),
    );
    $payload = json_encode([
        '__componenta_cqrs' => 1,
        'data' => $data,
    ], JSON_THROW_ON_ERROR);

    expect(fn() => $serializer->deserialize($payload, PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, $message);
})->with([
    'invalid actor UUID' => [[
        'actor' => 'not-a-uuid',
        'id' => 42,
        'tags' => [],
    ], 'not a valid UUID'],
    'unknown field' => [[
        'actor' => (new FakeActor(1))->uuid->toString(),
        'id' => 42,
        'tags' => [],
        'unexpected' => true,
    ], 'unknown field'],
]);
