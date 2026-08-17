<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\CommandSerializerSupportInterface;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\Identity\IdentityInterface;
use Componenta\Identity\Uuid;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;

final readonly class PolicyTransportIdentityActor implements IdentityInterface
{
    public UuidInterface $uuid;

    public function __construct(int $id)
    {
        $this->uuid = Uuid::fromString(sprintf(
            '00000000-0000-7000-8000-%012d',
            $id,
        ));
    }
}

final readonly class PolicyTransportActorCommand implements ActorAwareInterface
{
    /** @param list<string> $tags */
    public function __construct(
        public object $actor,
        public int $id,
        public array $tags = [],
    ) {}
}

final readonly class PolicyTransportAnonymousCommand
{
    public function __construct(public int $id) {}
}

final class PolicyTransportActorReplacingCommand implements ActorAwareInterface
{
    public object $actor;

    public function __construct(object $actor)
    {
        $this->actor = new PolicyTransportIdentityActor(1);
    }
}

final class PolicyTransportMutatingCommand implements ActorAwareInterface
{
    public int $id;

    public function __construct(
        public object $actor,
        int $id,
    ) {
        $this->id = $id + 1;
    }
}

final readonly class PolicyTransportNestedArrayCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public array $data,
    ) {}
}

final class PolicyTransportHookedPropertyCommand implements ActorAwareInterface
{
    public static int $reads = 0;

    public string $value {
        get {
            ++self::$reads;

            return 'computed';
        }
    }

    public function __construct(
        public object $actor,
        string $value,
    ) {}
}

final class PolicyTransportCallableCommand implements ActorAwareInterface
{
    public mixed $callback;

    public function __construct(
        public object $actor,
        callable $callback,
    ) {
        $this->callback = $callback;
    }
}

final readonly class PolicyTransportPrivateStateCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        private string $secret,
    ) {}
}

final class PolicyTransportActorRepository implements ActorRepositoryInterface
{
    /** @var list<string> */
    public array $requested = [];

    public function __construct(public ?object $actor) {}

    public function findByUuid(UuidInterface $uuid): ?object
    {
        $this->requested[] = $uuid->toString();

        return $this->actor;
    }
}

/** @param array<string, mixed> $data */
function actorAwarePayload(array $data): string
{
    return json_encode([
        '__componenta_cqrs' => 2,
        'data' => $data,
    ], JSON_THROW_ON_ERROR);
}

/** @return array{type: 'identity', uuid: string} */
function identityActorReference(IdentityInterface $actor): array
{
    return [
        'type' => 'identity',
        'uuid' => $actor->uuid->toString(),
    ];
}

it('supports only ActorAware command objects and classes', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer(new PolicyTransportActorRepository(null));

    expect($serializer)->toBeInstanceOf(CommandSerializerSupportInterface::class)
        ->and($serializer->supportsCommand(new PolicyTransportActorCommand(new Guest(), 1)))->toBeTrue()
        ->and($serializer->supportsCommand(PolicyTransportActorCommand::class))->toBeTrue()
        ->and($serializer->supportsCommand(new PolicyTransportAnonymousCommand(1)))->toBeFalse()
        ->and($serializer->supportsCommand(PolicyTransportAnonymousCommand::class))->toBeFalse();
});

it('rejects direct use for a command it does not own', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer(new PolicyTransportActorRepository(null));

    expect(fn() => $serializer->serialize(new PolicyTransportAnonymousCommand(1)))
        ->toThrow(TransportException::class, 'does not support command');
});

it('writes a tagged identity reference and restores the current repository actor', function (): void {
    $producerActor = new PolicyTransportIdentityActor(1);
    $currentActor = new PolicyTransportIdentityActor(1);
    $repository = new PolicyTransportActorRepository($currentActor);
    $serializer = new ActorAwareJsonCommandSerializer($repository);

    $payload = $serializer->serialize(
        new PolicyTransportActorCommand($producerActor, 42, ['one', 'two']),
    );
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($payload, PolicyTransportActorCommand::class);

    expect($decoded)->toBe([
        '__componenta_cqrs' => 2,
        'data' => [
            'actor' => identityActorReference($producerActor),
            'id' => 42,
            'tags' => ['one', 'two'],
        ],
    ])->and($restored->actor)->toBe($currentActor)
        ->and($restored->id)->toBe(42)
        ->and($restored->tags)->toBe(['one', 'two'])
        ->and($repository->requested)->toBe([$producerActor->uuid->toString()]);
});

it('round-trips Guest without repository lookup', function (): void {
    $repository = new PolicyTransportActorRepository(null);
    $serializer = new ActorAwareJsonCommandSerializer($repository);

    $payload = $serializer->serialize(new PolicyTransportActorCommand(new Guest(), 42));
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($payload, PolicyTransportActorCommand::class);

    expect($decoded['data']['actor'])->toBe(['type' => 'guest'])
        ->and($restored->actor)->toBeInstanceOf(Guest::class)
        ->and($repository->requested)->toBe([]);
});

it('rejects application-specific actors so a higher-priority serializer can own them', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer(new PolicyTransportActorRepository(null));

    expect(fn() => $serializer->serialize(
        new PolicyTransportActorCommand(new stdClass(), 42),
    ))->toThrow(TransportException::class, 'application-specific serializer');
});

it('fails closed when an identity actor cannot be restored', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(new PolicyTransportActorRepository(null));

    expect(fn() => $serializer->deserialize(actorAwarePayload([
        'actor' => identityActorReference($actor),
        'id' => 42,
        'tags' => [],
    ]), PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, 'was not found');
});

it('requires repository identity results to stay identifiable and match the requested UUID', function (
    object $repositoryActor,
    string $message,
): void {
    $requested = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($repositoryActor),
    );

    expect(fn() => $serializer->deserialize(actorAwarePayload([
        'actor' => identityActorReference($requested),
        'id' => 42,
        'tags' => [],
    ]), PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, $message);
})->with([
    'non-identifiable result' => [new Guest(), 'non-identifiable actor'],
    'different identity' => [new PolicyTransportIdentityActor(2), 'different identity'],
]);

it('rejects a constructor that replaces the restored actor instance', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );

    expect(fn() => $serializer->deserialize(actorAwarePayload([
        'actor' => identityActorReference($actor),
    ]), PolicyTransportActorReplacingCommand::class))
        ->toThrow(TransportException::class, 'replaced the actor instance');
});

it('rejects reconstruction that changes non-actor constructor-backed state', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $payload = $serializer->serialize(new PolicyTransportMutatingCommand($actor, 1));

    expect(fn() => $serializer->deserialize($payload, PolicyTransportMutatingCommand::class))
        ->toThrow(TransportException::class, 'changed constructor-backed field "id"');
});

it('rejects recursive arrays before unbounded recursion can exhaust the process', function (): void {
    $recursive = [];
    $recursive['self'] = &$recursive;
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );

    expect(fn() => $serializer->serialize(new PolicyTransportNestedArrayCommand($actor, $recursive)))
        ->toThrow(TransportException::class, 'maximum JSON nesting depth');
});

it('rejects malformed or obsolete actor-aware wire formats', function (
    mixed $payload,
    string $message,
): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $encoded = is_string($payload)
        ? $payload
        : json_encode($payload, JSON_THROW_ON_ERROR);

    expect(fn() => $serializer->deserialize($encoded, PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, $message);
})->with([
    'invalid JSON' => ['{', 'Failed to deserialize command'],
    'missing version envelope' => [[
        'actor' => ['type' => 'guest'],
        'id' => 42,
    ], 'must use the versioned envelope'],
    'obsolete version' => [[
        '__componenta_cqrs' => 1,
        'data' => [],
    ], 'Unsupported command payload version'],
    'extra envelope field' => [[
        '__componenta_cqrs' => 2,
        'data' => [],
        'extra' => true,
    ], 'Invalid versioned command payload envelope'],
    'uuid-only actor reference' => [[
        '__componenta_cqrs' => 2,
        'data' => [
            'actor' => (new PolicyTransportIdentityActor(1))->uuid->toString(),
            'id' => 42,
            'tags' => [],
        ],
    ], 'must be a tagged JSON object'],
    'unknown actor type' => [[
        '__componenta_cqrs' => 2,
        'data' => [
            'actor' => ['type' => 'system'],
            'id' => 42,
            'tags' => [],
        ],
    ], 'Unsupported actor reference type'],
]);

it('rejects malformed tagged actor references', function (
    mixed $actorReference,
    string $message,
): void {
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository(new PolicyTransportIdentityActor(1)),
    );

    expect(fn() => $serializer->deserialize(actorAwarePayload([
        'actor' => $actorReference,
        'id' => 42,
        'tags' => [],
    ]), PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, $message);
})->with([
    'guest extra state' => [['type' => 'guest', 'id' => 1], 'Guest actor reference'],
    'identity misses uuid' => [['type' => 'identity'], 'Identity actor reference'],
    'identity malformed uuid' => [[
        'type' => 'identity',
        'uuid' => 'not-a-uuid',
    ], 'not a valid UUID'],
    'identity extra state' => [[
        'type' => 'identity',
        'uuid' => (new PolicyTransportIdentityActor(1))->uuid->toString(),
        'role' => 'admin',
    ], 'Identity actor reference'],
]);

it('rejects executable and non-stored command shapes without invoking hooks', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );

    PolicyTransportHookedPropertyCommand::$reads = 0;

    expect(fn() => $serializer->serialize(
        new PolicyTransportHookedPropertyCommand($actor, 'input'),
    ))->toThrow(TransportException::class, 'hooked or virtual property')
        ->and(PolicyTransportHookedPropertyCommand::$reads)->toBe(0);

    expect(fn() => $serializer->serialize(
        new PolicyTransportCallableCommand($actor, 'strlen'),
    ))->toThrow(TransportException::class, 'executable callable constructor parameter');

    expect(fn() => $serializer->serialize(
        new PolicyTransportPrivateStateCommand($actor, 'secret'),
    ))->toThrow(TransportException::class, 'to be public');
});
