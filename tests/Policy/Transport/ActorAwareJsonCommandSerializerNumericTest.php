<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;

final readonly class ActorAwareNumericRepository implements ActorRepositoryInterface
{
    public function findByUuid(UuidInterface $uuid): ?object
    {
        return null;
    }
}

final readonly class ActorAwareFloatCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public float $value,
    ) {}
}

final readonly class ActorAwareMixedNumericCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public mixed $value,
    ) {}
}

final class ActorAwareNumericMutatingCommand implements ActorAwareInterface
{
    public mixed $value;

    public function __construct(
        public object $actor,
        mixed $value,
    ) {
        $this->value = (float) $value;
    }
}

function actorAwareNumericSerializer(): ActorAwareJsonCommandSerializer
{
    return new ActorAwareJsonCommandSerializer(new ActorAwareNumericRepository());
}

function actorAwareNumericPayload(string $field, mixed $value): string
{
    return json_encode([
        '__componenta_cqrs' => 2,
        'data' => [
            'actor' => ['type' => 'guest'],
            $field => $value,
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
}

it('preserves actor-aware float wire types exactly including nested values', function (): void {
    $serializer = actorAwareNumericSerializer();
    $payload = $serializer->serialize(new ActorAwareMixedNumericCommand(
        new Guest(),
        [
            'top' => 1.0,
            'negative_zero' => -0.0,
        ],
    ));
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($payload, ActorAwareMixedNumericCommand::class);

    expect($decoded['data']['value']['top'])->toBeFloat()->toBe(1.0)
        ->and($decoded['data']['value']['negative_zero'])->toBeFloat()
        ->and(bin2hex(pack('E', $decoded['data']['value']['negative_zero'])))
        ->toBe(bin2hex(pack('E', -0.0)))
        ->and($restored->value['top'])->toBeFloat()->toBe(1.0)
        ->and(bin2hex(pack('E', $restored->value['negative_zero'])))
        ->toBe(bin2hex(pack('E', -0.0)));
});

it('fails closed when PHP JSON precision would change actor-aware state', function (): void {
    $previous = ini_get('serialize_precision');
    ini_set('serialize_precision', '2');

    try {
        expect(fn() => actorAwareNumericSerializer()->serialize(
            new ActorAwareMixedNumericCommand(new Guest(), 1.23456789012345),
        ))->toThrow(TransportException::class, 'JSON encoding changed command state');
    } finally {
        if (is_string($previous)) {
            ini_set('serialize_precision', $previous);
        }
    }
});

it('rejects a JSON integer for an actor-aware float field', function (): void {
    expect(fn() => actorAwareNumericSerializer()->deserialize(
        actorAwareNumericPayload('value', 1),
        ActorAwareFloatCommand::class,
    ))->toThrow(TransportException::class, 'must match float; int given');
});

it('rejects out-of-range JSON integers before PHP coerces actor-aware payloads to float', function (): void {
    $outOfRange = (string) PHP_INT_MAX . '0';
    $payload = sprintf(
        '{"__componenta_cqrs":2,"data":{"actor":{"type":"guest"},"value":%s}}',
        $outOfRange,
    );

    expect(fn() => actorAwareNumericSerializer()->deserialize(
        $payload,
        ActorAwareMixedNumericCommand::class,
    ))->toThrow(TransportException::class, 'integer outside the PHP integer range');
});

it('rejects actor-aware numeric type mutation for mixed state', function (): void {
    expect(fn() => actorAwareNumericSerializer()->deserialize(
        actorAwareNumericPayload('value', 1),
        ActorAwareNumericMutatingCommand::class,
    ))->toThrow(TransportException::class, 'changed constructor-backed field "value"');
});
