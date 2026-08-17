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
        $this->actor = new FakeActor(1);
    }
}

final class PolicyTransportStaticPropertyCommand implements ActorAwareInterface
{
    public static string $global = 'must-not-leak';

    public function __construct(
        public ActorInterface $actor,
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
        public ActorInterface $actor,
        string $value,
    ) {}
}

final readonly class PolicyTransportStrictTypesCommand implements ActorAwareInterface
{
    public function __construct(
        public ActorInterface $actor,
        public int $id,
        public float $ratio,
        public string|bool|null $label,
    ) {}
}

final class PolicyTransportCallableCommand implements ActorAwareInterface
{
    public mixed $callback;

    public function __construct(
        public ActorInterface $actor,
        callable $callback,
    ) {
        $this->callback = $callback;
    }
}

final readonly class PolicyTransportStringCapabilityCommand implements ActorAwareInterface
{
    public function __construct(
        public ActorInterface $actor,
        public string $callback,
    ) {}
}

final readonly class PolicyTransportObjectPropertyCommand implements ActorAwareInterface
{
    public function __construct(
        public ActorInterface $actor,
        public DateTimeImmutable $at,
    ) {}
}

final readonly class PolicyTransportPrivateStateCommand implements ActorAwareInterface
{
    public function __construct(
        public ActorInterface $actor,
        private string $secret,
    ) {}
}

final class PolicyTransportVariadicCommand implements ActorAwareInterface
{
    /** @var list<string> */
    public array $values;

    public function __construct(
        public ActorInterface $actor,
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

it('round-trips actor-aware commands through versioned, reordered, and legacy payloads', function (): void {
    $originalActor = new FakeActor(1);
    $currentActor = new FakeActor(1);
    $repository = new PolicyTransportActorRepository($currentActor);
    $serializer = new ActorAwareJsonCommandSerializer($repository);
    $command = new PolicyTransportActorCommand($originalActor, 42, ['one', 'two']);

    $payload = $serializer->serialize($command);
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($payload, PolicyTransportActorCommand::class);
    $reordered = $serializer->deserialize(json_encode([
        'data' => [
            'tags' => [],
            'id' => 7,
            'actor' => $originalActor->uuid->toString(),
        ],
        '__componenta_cqrs' => 1,
    ], JSON_THROW_ON_ERROR), PolicyTransportActorCommand::class);
    $legacy = $serializer->deserialize(json_encode([
        'actor' => $originalActor->uuid->toString(),
        'id' => 8,
        'tags' => ['legacy'],
    ], JSON_THROW_ON_ERROR), PolicyTransportActorCommand::class);

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
        ->and($reordered->actor)->toBe($currentActor)
        ->and($reordered->id)->toBe(7)
        ->and($legacy->actor)->toBe($currentActor)
        ->and($legacy->id)->toBe(8)
        ->and($legacy->tags)->toBe(['legacy'])
        ->and($repository->requested)->toBe([
            $originalActor->uuid->toString(),
            $originalActor->uuid->toString(),
            $originalActor->uuid->toString(),
        ]);
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
    $actor = new FakeActor(1);
    $payload = (new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    ))->serialize(new PolicyTransportStaticPropertyCommand($actor, 9));

    expect(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data'])->toBe([
        'actor' => $actor->uuid->toString(),
        'id' => 9,
    ]);
});

it('rejects hooked properties without invoking their getters', function (): void {
    PolicyTransportHookedPropertyCommand::$reads = 0;
    $actor = new FakeActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );

    expect(fn() => $serializer->serialize(
        new PolicyTransportHookedPropertyCommand($actor, 'input'),
    ))->toThrow(TransportException::class, 'hooked or virtual property')
        ->and(PolicyTransportHookedPropertyCommand::$reads)->toBe(0);
});

it('uses strict PHP type semantics on the actor-aware path', function (): void {
    $actor = new FakeActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $payload = json_encode([
        'actor' => $actor->uuid->toString(),
        'id' => 12,
        'ratio' => 3,
        'label' => null,
    ], JSON_THROW_ON_ERROR);

    $command = $serializer->deserialize($payload, PolicyTransportStrictTypesCommand::class);

    expect($command)->toBeInstanceOf(PolicyTransportStrictTypesCommand::class)
        ->and($command->actor)->toBe($actor)
        ->and($command->id)->toBe(12)
        ->and($command->ratio)->toBe(3.0)
        ->and($command->label)->toBeNull();
});

it('rejects scalar coercion and invalid union members on the actor-aware path', function (
    array $data,
    string $message,
): void {
    $actor = new FakeActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $payload = json_encode([
        'actor' => $actor->uuid->toString(),
        ...$data,
    ], JSON_THROW_ON_ERROR);

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
    $actor = new FakeActor(1);
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

it('keeps executable-looking strings as ordinary actor-aware command data', function (): void {
    $actor = new FakeActor(1);
    $serializer = new ActorAwareJsonCommandSerializer(
        new PolicyTransportActorRepository($actor),
    );
    $command = $serializer->deserialize(json_encode([
        'actor' => $actor->uuid->toString(),
        'callback' => 'system',
    ], JSON_THROW_ON_ERROR), PolicyTransportStringCapabilityCommand::class);

    expect($command)->toBeInstanceOf(PolicyTransportStringCapabilityCommand::class)
        ->and($command->actor)->toBe($actor)
        ->and($command->callback)->toBe('system');
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

it('rejects a constructor that replaces the repository actor with another instance of the same identity', function (): void {
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
        ->toThrow(TransportException::class, 'replaced the actor instance');
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

it('rejects malformed actor references and invalid payload envelopes', function (
    mixed $payload,
    string $message,
): void {
    $actor = new FakeActor(1);
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
    'unsupported version' => [[
        '__componenta_cqrs' => 2,
        'data' => [],
    ], 'Unsupported command payload version'],
    'versioned envelope has an extra field' => [[
        '__componenta_cqrs' => 1,
        'data' => [],
        'extra' => true,
    ], 'Invalid versioned command payload envelope'],
    'versioned data is a list' => [[
        '__componenta_cqrs' => 1,
        'data' => ['actor-uuid'],
    ], 'Invalid versioned command payload envelope'],
    'actor reference is not a string' => [[
        'actor' => 1,
        'id' => 42,
        'tags' => [],
    ], 'must be a UUID string'],
    'actor UUID is malformed' => [[
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

it('rejects unsupported actor-aware command shapes', function (
    Closure $operation,
    string $message,
): void {
    expect($operation)->toThrow(TransportException::class, $message);
})->with([
    'nested object property' => [
        fn() => (new ActorAwareJsonCommandSerializer(
            new PolicyTransportActorRepository(new FakeActor(1)),
        ))->serialize(new PolicyTransportObjectPropertyCommand(
            new FakeActor(1),
            new DateTimeImmutable(),
        )),
        'configure a custom serializer',
    ],
    'private constructor-backed state' => [
        fn() => (new ActorAwareJsonCommandSerializer(
            new PolicyTransportActorRepository(new FakeActor(1)),
        ))->serialize(new PolicyTransportPrivateStateCommand(new FakeActor(1), 'secret')),
        'to be public',
    ],
    'variadic constructor' => [
        fn() => (new ActorAwareJsonCommandSerializer(
            new PolicyTransportActorRepository(new FakeActor(1)),
        ))->serialize(new PolicyTransportVariadicCommand(new FakeActor(1), 'one')),
        'variadic or by-reference',
    ],
]);
