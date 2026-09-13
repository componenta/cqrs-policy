<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;

function actorPointerSerializer(): ActorAwareJsonCommandSerializer
{
    return new ActorAwareJsonCommandSerializer(new class () implements ActorRepositoryInterface {
        public function findByUuid(UuidInterface $uuid): ?object
        {
            throw new LogicException('Guest requires no repository lookup.');
        }
    });
}

final readonly class ActorPointerStateCommand implements ActorAwareInterface
{
    public function __construct(public object $actor, public array $data)
    {
    }
}

it('rejects actor-aware command array pointer loss without changing the source', function (bool $nested, int $steps): void {
    $values = ['first' => 'a', 'second' => 'b'];
    for ($step = 0; $step < $steps; ++$step) {
        next($values);
    }
    $command = new ActorPointerStateCommand(new Guest(), $nested ? ['items' => $values] : $values);

    expect(fn () => actorPointerSerializer()->serialize($command))
        ->toThrow(TransportException::class, 'JSON encoding changed command state');
    $unchanged = $nested ? $command->data['items'] : $command->data;
    expect(key($unchanged))->toBe($steps === 1 ? 'second' : null)
        ->and(current($unchanged))->toBe($steps === 1 ? 'b' : false);
})->with([false, true])->with([1, 2]);

final class ActorPointerChangingConstructorCommand implements ActorAwareInterface
{
    public function __construct(public object $actor, public array $data)
    {
        next($this->data);
    }
}

it('rejects actor-aware reconstruction that changes only the array pointer', function (): void {
    $payload = '{"__componenta_cqrs":2,"data":{"actor":{"type":"guest"},"data":["a","b"]}}';

    expect(fn () => actorPointerSerializer()->deserialize($payload, ActorPointerChangingConstructorCommand::class))
        ->toThrow(TransportException::class, 'changed constructor-backed field');
});

it('round trips actor-aware commands with portable array pointer state', function (array $data): void {
    $serializer = actorPointerSerializer();
    $command = new ActorPointerStateCommand(new Guest(), $data);
    $restored = $serializer->deserialize($serializer->serialize($command), $command::class);

    expect($restored)->toBeInstanceOf(ActorPointerStateCommand::class)
        ->and($restored->data)->toBe($data)
        ->and(key($restored->data))->toBe(key($data))
        ->and(current($restored->data))->toBe(current($data));
})->with([
    'empty' => [[]],
    'false at zero' => [[false]],
    'null at negative key' => [[-2 => null]],
    'first string key' => [['first' => 'a', 'second' => 'b']],
]);
