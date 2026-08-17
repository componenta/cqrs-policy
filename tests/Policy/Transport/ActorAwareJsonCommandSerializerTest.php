<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\CommandSerializerInterface;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\CQRS\Tests\Fixture\FakeActor;
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

final readonly class PolicyTransportDefaultActorCommand implements ActorAwareInterface
{
    public function __construct(public object $actor = new FakeActor(9)) {}
}

final class PolicyTransportActorReplacingCommand implements ActorAwareInterface
{
    public object $actor;

    public function __construct(object $actor)
    {
        $this->actor = new PolicyTransportIdentityActor(1);
    }
}

final class PolicyTransportStaticPropertyCommand implements ActorAwareInterface
{
    public static string $global = 'must-not-leak';

    public function __construct(
        public object $actor,
        public int $id,
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

final readonly class PolicyTransportStrictTypesCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public int $id,
        public float $ratio,
        public string|bool|null $label,
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

final readonly class PolicyTransportStringCapabilityCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public string $callback,
    ) {}
}

final readonly class PolicyTransportObjectPropertyCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public DateTimeImmutable $at,
    ) {}
}

final readonly class PolicyTransportPrivateStateCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        private string $secret,
    ) {}
}

final class PolicyTransportVariadicCommand implements ActorAwareInterface
{
    /** @var list<string> */
    public array $values;

    public function __construct(
        public object $actor,
        string ...$values,
    ) {
        $this->values = $values;
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

    public function __construct(public ?object $actor) {}

    public function findByUuid(UuidInterface $uuid): ?object
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

/** @param array<string, mixed> $data */
function actorAwarePayload(array $data): string
{
    return json_encode([
        '__componenta_cqrs' => 2,
        'data' => $data,
    ], JSON_THROW_ON_ERROR);
}

/** @return array{type: string, uuid: string} */
function identityActorReference(IdentityInterface $actor): array
{
    return [
        'type' => 'identity',
        'uuid' => $actor->uuid->toString(),
    ];
}

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
    ])->and($restored)->toBeInstanceOf(PolicyTransportActorCommand::class)
        ->and($restored->actor)->toBe($currentActor)
        ->and($restored->id)->toBe(42)
        ->and($restored->tags)->toBe(['one', 'two'])
        ->and($repository->requested)->toBe([$producerActor->uuid->toString()]);
});

it('round-trips Guest as a stateless tagged reference without repository lookup', function (): void {
    $repository = new PolicyTransportActorRepository(null);
    $serializer = new ActorAwareJsonCommandSerializer($repository);

    $payload = $serializer->serialize(new PolicyTransportActorCommand(new Guest(), 42));
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($payload, PolicyTransportActorCommand::class);

    expect($decoded['data']['actor'])->toBe(['type' => 'guest'])
        ->and($restored->actor)->toBeInstanceOf(Guest::class)
        ->and($repository->requested)->toBe([]);
});

it('rejects application-specific actors unless the application replaces the serializer', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer(new PolicyTransportActorRepository(null));

    expect(fn() => $serializer->serialize(
        new PolicyTransportActorCommand(new stdClass(), 42),
    ))->toThrow(
        TransportException::class,
        'configure a custom Componenta\\CQRS\\Command\\Transport\\CommandSerializerInterface',
    );
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

it('ignores static properties on actor-aware commands', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $payload = (new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    ))->serialize(new PolicyTransportStaticPropertyCommand($actor, 9));

    expect(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data'])->toBe([
        'actor' => identityActorReference($actor),
        'id' => 9,
    ]);
});

it('rejects hooked properties without invoking their getters', function (): void {
    PolicyTransportHookedPropertyCommand::$reads = 0;
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );

    expect(fn() => $serializer->serialize(
        new PolicyTransportHookedPropertyCommand($actor, 'input'),
    ))->toThrow(TransportException::class, 'hooked or virtual property')
        ->and(PolicyTransportHookedPropertyCommand::$reads)->toBe(0);
});

it('uses strict PHP type semantics on the actor-aware path', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $payload = actorAwarePayload([
        'actor' => identityActorReference($actor),
        'id' => 12,
        'ratio' => 3,
        'label' => null,
    ]);

    $command = $serializer->deserialize($payload, PolicyTransportStrictTypesCommand::class);

    expect($command->actor)->toBe($actor)
        ->and($command->id)->toBe(12)
        ->and($command->ratio)->toBe(3.0)
        ->and($command->label)->toBeNull();
});

it('rejects scalar coercion and invalid union members on the actor-aware path', function (
    array $data,
    string $message,
): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $payload = actorAwarePayload([
        'actor' => identityActorReference($actor),
        ...$data,
    ]);

    expect(fn() => $serializer->deserialize($payload, PolicyTransportStrictTypesCommand::class))
        ->toThrow(TransportException::class, $message);
})->with([
    'string is not coerced to int' => [[
        'id' => '12',
        'ratio' => 3,
        'label' => null,
    ], 'must match int; string given'],
    'bool is not accepted as float' => [[
        'id' => 12,
        'ratio' => true,
        'label' => null,
    ], 'must match float; bool given'],
    'int is not a string-or-bool union member' => [[
        'id' => 12,
        'ratio' => 3,
        'label' => 1,
    ], 'must match string|bool|null; int given'],
]);

it('rejects executable capability types before serializing actor-aware commands', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );

    expect(fn() => $serializer->serialize(
        new PolicyTransportCallableCommand($actor, 'strlen'),
    ))->toThrow(
        TransportException::class,
        'does not support executable callable constructor parameter',
    );
});

it('keeps executable-looking strings as ordinary command data', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $command = $serializer->deserialize(actorAwarePayload([
        'actor' => identityActorReference($actor),
        'callback' => 'system',
    ]), PolicyTransportStringCapabilityCommand::class);

    expect($command->actor)->toBe($actor)
        ->and($command->callback)->toBe('system');
});

it('fails closed when an identity actor no longer exists', function (): void {
    $actor = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository(null),
    );

    expect(fn() => $serializer->deserialize(actorAwarePayload([
        'actor' => identityActorReference($actor),
        'id' => 42,
        'tags' => [],
    ]), PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, 'was not found');
});

it('requires identity repository results to remain identifiable and match the requested UUID', function (
    object $repositoryActor,
    string $message,
): void {
    $requested = new PolicyTransportIdentityActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($repositoryActor),
    );
    $payload = actorAwarePayload([
        'actor' => identityActorReference($requested),
        'id' => 42,
        'tags' => [],
    ]);

    expect(fn() => $serializer->deserialize($payload, PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, $message);
})->with([
    'non-identifiable result' => [new Guest(), 'non-identifiable actor'],
    'different identity' => [new PolicyTransportIdentityActor(2), 'different identity'],
]);

it('requires an explicit actor reference even when the constructor has a default', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository(new FakeActor(9)),
    );

    expect(fn() => $serializer->deserialize(actorAwarePayload([]), PolicyTransportDefaultActorCommand::class))
        ->toThrow(TransportException::class, 'missing its actor reference');
});

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

it('requires the current versioned actor-aware envelope', function (
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
    'JSON list is not a command object' => [[1], 'expected a JSON object'],
    'missing version envelope' => [[
        'actor' => ['type' => 'guest'],
        'id' => 42,
    ], 'must use the versioned envelope'],
    'unsupported version' => [[
        '__componenta_cqrs' => 1,
        'data' => [],
    ], 'Unsupported command payload version'],
    'versioned envelope has an extra field' => [[
        '__componenta_cqrs' => 2,
        'data' => [],
        'extra' => true,
    ], 'Invalid versioned command payload envelope'],
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
    'scalar actor reference' => [1, 'must be a tagged JSON object'],
    'UUID string is no longer accepted' => [
        (new PolicyTransportIdentityActor(1))->uuid->toString(),
        'must be a tagged JSON object',
    ],
    'unknown tagged type' => [['type' => 'system'], 'Unsupported actor reference type'],
    'guest has extra state' => [['type' => 'guest', 'id' => 1], 'Guest actor reference'],
    'identity misses uuid' => [['type' => 'identity'], 'Identity actor reference'],
    'identity UUID is malformed' => [[
        'type' => 'identity',
        'uuid' => 'not-a-uuid',
    ], 'not a valid UUID'],
    'identity has extra state' => [[
        'type' => 'identity',
        'uuid' => (new PolicyTransportIdentityActor(1))->uuid->toString(),
        'role' => 'admin',
    ], 'Identity actor reference'],
]);

it('rejects unknown command fields', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository(null),
    );

    expect(fn() => $serializer->deserialize(actorAwarePayload([
        'actor' => ['type' => 'guest'],
        'id' => 42,
        'tags' => [],
        'unexpected' => true,
    ]), PolicyTransportActorCommand::class))
        ->toThrow(TransportException::class, 'unknown field');
});

it('rejects unsupported actor-aware command shapes', function (
    Closure $operation,
    string $message,
): void {
    expect($operation)->toThrow(TransportException::class, $message);
})->with([
    'nested object property' => [
        fn() => (new ActorAwareJsonCommandSerializer(
            new PolicyTransportActorRepository(new PolicyTransportIdentityActor(1)),
        ))->serialize(new PolicyTransportObjectPropertyCommand(
            new PolicyTransportIdentityActor(1),
            new DateTimeImmutable(),
        )),
        'configure a custom serializer',
    ],
    'private constructor-backed state' => [
        fn() => (new ActorAwareJsonCommandSerializer(
            new PolicyTransportActorRepository(new PolicyTransportIdentityActor(1)),
        ))->serialize(new PolicyTransportPrivateStateCommand(
            new PolicyTransportIdentityActor(1),
            'secret',
        )),
        'to be public',
    ],
    'variadic constructor' => [
        fn() => (new ActorAwareJsonCommandSerializer(
            new PolicyTransportActorRepository(new PolicyTransportIdentityActor(1)),
        ))->serialize(new PolicyTransportVariadicCommand(
            new PolicyTransportIdentityActor(1),
            'one',
        )),
        'variadic or by-reference',
    ],
]);
