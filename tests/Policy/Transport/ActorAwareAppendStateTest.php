<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBus;
use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;
use Componenta\CQRS\Command\Transport\JsonOperationContextSerializer;
use Componenta\CQRS\Command\Transport\TransportException;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Command\Transport\TransportRegistry;
use Componenta\CQRS\Transport\Attribute\Async;

function actorAppendStateSerializer(): ActorAwareJsonCommandSerializer
{
    return new ActorAwareJsonCommandSerializer(new class () implements ActorRepositoryInterface {
        public function findByUuid(UuidInterface $uuid): ?object
        {
            throw new LogicException('A Guest must not require actor lookup.');
        }
    });
}

#[Async]
final readonly class ActorAppendStateCommand implements ActorAwareInterface
{
    public function __construct(public object $actor, public array $data)
    {
    }
}

it('rejects lost array append state before sending or executing an actor-aware JSON command', function (array $values, int $removed, int $next): void {
    unset($values[$removed]);
    $command = new ActorAppendStateCommand(new Guest(), ['items' => $values]);
    $transport = $this->createMock(TransportInterface::class);
    $transport->expects($this->never())->method('send');
    $registry = new TransportRegistry();
    $registry->register('default', $transport);
    $terminal = new class () implements OperationHandlerInterface {
        public int $calls = 0;

        public function handle(OperationInterface $operation): OperationInterface
        {
            ++$this->calls;
            return $operation;
        }
    };
    $bus = new CommandBus($terminal, [new TransportMiddleware(
        $registry,
        actorAppendStateSerializer(),
        new ReflectionCommandMetadataProvider(),
        new JsonOperationContextSerializer(),
    )]);

    expect(fn () => $bus->dispatch($command))
        ->toThrow(TransportException::class, 'JSON encoding changed command state');
    expect($terminal->calls)->toBe(0);
    $unchanged = $command->data['items'];
    $unchanged[] = 'next';
    expect(array_key_last($unchanged))->toBe($next);
})->with([
    'removed tail' => [['kept', 'removed'], 1, 2],
    'emptied array' => [['removed'], 0, 1],
    'negative indices' => [[-5 => 'kept', -4 => 'removed'], -4, -3],
    'removed maximum index' => [[0 => 'kept', PHP_INT_MAX => 'removed'], PHP_INT_MAX, PHP_INT_MAX],
]);

it('preserves representable array append behaviour in actor-aware JSON commands', function (array $values, int $next): void {
    $serializer = actorAppendStateSerializer();
    $command = new ActorAppendStateCommand(new Guest(), ['items' => $values]);
    $restored = $serializer->deserialize($serializer->serialize($command), $command::class);
    expect($restored->data)->toBe(['items' => $values]);
    $original = $command->data['items'];
    $received = $restored->data['items'];
    $original[] = 'next';
    $received[] = 'next';

    expect(array_key_last($original))->toBe($next)
        ->and(array_key_last($received))->toBe($next);
})->with([
    'list' => [['kept'], 1],
    'sparse array' => [[2 => 'kept', 8 => 'last'], 9],
    'negative index' => [[-5 => 'kept'], -4],
]);

it('preserves an occupied maximum index in an actor-aware command without probing past the array limit', function (): void {
    $serializer = actorAppendStateSerializer();
    $command = new ActorAppendStateCommand(new Guest(), [PHP_INT_MAX => 'kept']);
    $restored = $serializer->deserialize($serializer->serialize($command), $command::class);
    expect($restored->data)->toBe([PHP_INT_MAX => 'kept']);
    expect(static function () use ($restored): void {
        $values = $restored->data;
        $values[] = 'next';
    })->toThrow(Error::class, 'Cannot add element');
});

final class ActorReconstructedAppendStateCommand implements ActorAwareInterface
{
    public function __construct(public object $actor, public array $data)
    {
        $this->data[] = 'temporary';
        unset($this->data[array_key_last($this->data)]);
    }
}

it('rejects actor-aware command reconstruction that changes only the next array index', function (): void {
    $payload = '{"__componenta_cqrs":2,"data":{"actor":{"type":"guest"},"data":["kept"]}}';

    expect(fn () => (actorAppendStateSerializer())->deserialize($payload, ActorReconstructedAppendStateCommand::class))
        ->toThrow(TransportException::class, 'changed constructor-backed field');
});
