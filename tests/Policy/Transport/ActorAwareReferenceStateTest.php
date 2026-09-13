<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBus;
use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\OperationInterface;
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
final class ActorReferenceStateCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public array $data = [],
        public int $first = 1,
        public int $second = 1,
    ) {
    }
}

it('rejects actor-aware PHP references before sending or executing the command', function (Closure $create): void {
    $command = $create();
    $transport = $this->createMock(TransportInterface::class);
    $transport->expects($this->never())->method('send');
    $actors = $this->createMock(ActorRepositoryInterface::class);
    $actors->expects($this->never())->method('findByUuid');
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
        new ActorAwareJsonCommandSerializer($actors),
        new ReflectionCommandMetadataProvider(),
        new JsonOperationContextSerializer(),
    )]);

    expect(fn () => $bus->dispatch($command))
        ->toThrow(TransportException::class, 'PHP reference');
    expect($terminal->calls)->toBe(0);
})->with([
    'nested array' => [static function (): object {
        $value = 1;
        return new ActorReferenceStateCommand(new Guest(), ['nested' => ['first' => &$value, 'second' => &$value]]);
    }],
    'between properties' => [static function (): object {
        $command = new ActorReferenceStateCommand(new Guest());
        $command->second = &$command->first;
        return $command;
    }],
]);


final class ActorHydratedReferencePropertiesCommand implements ActorAwareInterface
{
    public function __construct(public object $actor, public int $first, public int $second)
    {
        $this->second = &$this->first;
    }
}

final class ActorHydratedReferenceArrayCommand implements ActorAwareInterface
{
    public function __construct(public object $actor, public array $data)
    {
        $this->data['second'] = &$this->data['first'];
    }
}

it('rejects actor-aware reconstruction that introduces PHP references', function (string $class, array $data): void {
    $actors = $this->createMock(ActorRepositoryInterface::class);
    $actors->expects($this->never())->method('findByUuid');
    $serializer = new ActorAwareJsonCommandSerializer($actors);
    $payload = json_encode([
        '__componenta_cqrs' => 2,
        'data' => ['actor' => ['type' => 'guest'], ...$data],
    ], JSON_THROW_ON_ERROR);

    expect(fn () => $serializer->deserialize($payload, $class))
        ->toThrow(TransportException::class, 'PHP reference');
})->with([
    'between properties' => [ActorHydratedReferencePropertiesCommand::class, ['first' => 1, 'second' => 1]],
    'inside an array' => [ActorHydratedReferenceArrayCommand::class, ['data' => ['first' => 1, 'second' => 1]]],
]);


it('preserves ordinary actor-aware values and an independent reconstructed Guest', function (): void {
    $actors = $this->createMock(ActorRepositoryInterface::class);
    $actors->expects($this->never())->method('findByUuid');
    $serializer = new ActorAwareJsonCommandSerializer($actors);
    $sharedValue = ['value' => 1];
    $command = new ActorReferenceStateCommand(new Guest(), ['first' => $sharedValue, 'second' => $sharedValue]);
    $restored = $serializer->deserialize($serializer->serialize($command), $command::class);

    expect($restored->actor)->toBeInstanceOf(Guest::class)->not->toBe($command->actor)
        ->and($restored->data)->toBe(['first' => ['value' => 1], 'second' => ['value' => 1]]);
    $restored->data['first']['value'] = 2;
    $restored->first = 2;
    expect($restored->data['second']['value'])->toBe(1)
        ->and($restored->second)->toBe(1);
});
