<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;

#[AllowDynamicProperties]
final class ActorAwareDynamicStateCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public int $id,
    ) {}
}

#[AllowDynamicProperties]
final class ActorAwareDynamicHydrationCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public int $id,
    ) {
        $this->runtimeState = 'created';
    }
}

final readonly class ActorAwareDynamicStateRepository implements ActorRepositoryInterface
{
    public function findByUuid(UuidInterface $uuid): ?object
    {
        return null;
    }
}

it('rejects dynamic actor-aware command state instead of silently dropping it', function (): void {
    $command = new ActorAwareDynamicStateCommand(new Guest(), 1);
    $command->runtimeState = 'producer-only';
    $serializer = new ActorAwareJsonCommandSerializer(new ActorAwareDynamicStateRepository());

    expect(fn() => $serializer->serialize($command))
        ->toThrow(TransportException::class, 'unsupported dynamic property(s): runtimeState');
});

it('rejects dynamic state created while reconstructing an actor-aware command', function (): void {
    $serializer = new ActorAwareJsonCommandSerializer(new ActorAwareDynamicStateRepository());
    $payload = json_encode([
        '__componenta_cqrs' => 2,
        'data' => [
            'actor' => ['type' => 'guest'],
            'id' => 1,
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn() => $serializer->deserialize(
        $payload,
        ActorAwareDynamicHydrationCommand::class,
    ))->toThrow(TransportException::class, 'unsupported dynamic property(s): runtimeState');
});
