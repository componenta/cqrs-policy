<?php

declare(strict_types=1);

use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware;
use Componenta\CQRS\Command\Middleware\TransportMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\CQRS\Command\Transport\CommandWorker;
use Componenta\CQRS\Command\Transport\Envelope;
use Componenta\CQRS\Command\Transport\ExecutionMode;
use Componenta\CQRS\Command\Transport\TransportInterface;
use Componenta\CQRS\Policy\Transport\ActorAwareJsonCommandSerializer;
use Componenta\CQRS\Policy\Transport\ActorRepositoryInterface;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\Identity\UuidInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorInterface;
use Componenta\Policy\Context\ContextInterface;
use Componenta\Policy\Exception\DenyReason;
use Componenta\Policy\PolicyEnforcer;
use Componenta\Policy\PolicyInterface;
use Componenta\Policy\Provider\ArrayPolicyProvider;

final readonly class PolicyTransportWorkerCommand implements ActorAwareInterface
{
    public function __construct(
        public ActorInterface $actor,
        public int $id,
    ) {}
}

final class PolicyTransportWorkerRepository implements ActorRepositoryInterface
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

final class PolicyTransportWorkerPolicy implements PolicyInterface
{
    /** @var list<object> */
    public array $actors = [];

    public function enforce(object $actor, ContextInterface $context): true|DenyReason
    {
        $this->actors[] = $actor;

        return true;
    }
}

final class PolicyTransportWorkerBus implements CommandBusInterface
{
    /** @var list<object> */
    public array $commands = [];

    /** @var list<array<string, mixed>> */
    public array $attributes = [];

    public function __construct(private readonly PolicyMiddleware $policy) {}

    public function dispatch(object $command, array $attributes = []): OperationInterface
    {
        $this->commands[] = $command;
        $this->attributes[] = $attributes;

        return $this->policy->execute(
            Operation::create($command, $attributes),
            new readonly class implements OperationHandlerInterface {
                public function handle(OperationInterface $operation): OperationInterface
                {
                    return $operation->withResult(new OperationResult('handled'));
                }
            },
        );
    }
}

final class PolicyTransportWorkerTransport implements TransportInterface
{
    /** @var list<Envelope> */
    public array $acknowledged = [];

    /** @var list<Envelope> */
    public array $rejected = [];

    private bool $delivered = false;

    public function __construct(private readonly Envelope $envelope) {}

    public function send(Envelope $envelope, int $delay = 0): Envelope
    {
        return $envelope;
    }

    public function get(): ?Envelope
    {
        if ($this->delivered) {
            return null;
        }

        $this->delivered = true;

        return $this->envelope;
    }

    public function ack(Envelope $envelope): void
    {
        $this->acknowledged[] = $envelope;
    }

    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = $envelope;
    }
}

final readonly class PolicyTransportWorkerMetadata implements CommandMetadataProviderInterface
{
    public function get(object|string $command, string $attribute): ?object
    {
        return null;
    }

    public function isKnown(object|string $command): bool
    {
        return is_object($command)
            ? $command instanceof PolicyTransportWorkerCommand
            : $command === PolicyTransportWorkerCommand::class;
    }
}

function policyTransportWorkerMiddleware(
    PolicyTransportWorkerPolicy $policy,
): PolicyMiddleware {
    return new PolicyMiddleware(new PolicyEnforcer(
        new ArrayPolicyProvider(new FakeContainer(), [
            PolicyTransportWorkerCommand::class => $policy,
        ]),
    ));
}

it('restores the current repository actor before the worker redispatches through policy', function (): void {
    $producerActor = new FakeActor(1);
    $currentActor = new FakeActor(1);
    $policy = new PolicyTransportWorkerPolicy();
    $bus = new PolicyTransportWorkerBus(policyTransportWorkerMiddleware($policy));
    $repository = new PolicyTransportWorkerRepository($currentActor);
    $serializer = new ActorAwareJsonCommandSerializer($repository);
    $producerCommand = new PolicyTransportWorkerCommand($producerActor, 42);

    $producerOperation = $bus->dispatch($producerCommand, ['trace_id' => 'producer']);
    $envelope = new Envelope(
        operationId: 'operation-actor-round-trip',
        commandClass: PolicyTransportWorkerCommand::class,
        payload: $serializer->serialize($producerCommand),
        receiptHandle: 'receipt-1',
    );
    $transport = new PolicyTransportWorkerTransport($envelope);
    $worker = new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        transport: $transport,
        commands: new PolicyTransportWorkerMetadata(),
    );

    $processed = $worker->processOne();

    expect($producerOperation->result?->value)->toBe('handled')
        ->and($processed)->toBeTrue()
        ->and($policy->actors)->toHaveCount(2)
        ->and($policy->actors[0])->toBe($producerActor)
        ->and($policy->actors[1])->toBe($currentActor)
        ->and($bus->commands)->toHaveCount(2)
        ->and($bus->commands[1])->toBeInstanceOf(PolicyTransportWorkerCommand::class)
        ->and($bus->commands[1])->not->toBe($producerCommand)
        ->and($bus->commands[1]->actor)->toBe($currentActor)
        ->and($bus->attributes[1][CommandWorker::ATTR_ORIGINAL_OPERATION_ID] ?? null)
        ->toBe('operation-actor-round-trip')
        ->and($bus->attributes[1][TransportMiddleware::ATTR_EXECUTION_MODE] ?? null)
        ->toBe(ExecutionMode::SYNC)
        ->and($bus->attributes[1])->not->toHaveKey('__actor')
        ->and($repository->requested)->toBe([$producerActor->uuid->toString()])
        ->and($transport->acknowledged)->toBe([$envelope])
        ->and($transport->rejected)->toBe([]);
});

it('rejects the delivery without dispatch when the transported actor cannot be restored', function (): void {
    $actor = new FakeActor(1);
    $policy = new PolicyTransportWorkerPolicy();
    $bus = new PolicyTransportWorkerBus(policyTransportWorkerMiddleware($policy));
    $repository = new PolicyTransportWorkerRepository(null);
    $serializer = new ActorAwareJsonCommandSerializer($repository);
    $command = new PolicyTransportWorkerCommand($actor, 42);
    $envelope = new Envelope(
        operationId: 'operation-missing-actor',
        commandClass: PolicyTransportWorkerCommand::class,
        payload: $serializer->serialize($command),
        receiptHandle: 'receipt-2',
    );
    $transport = new PolicyTransportWorkerTransport($envelope);
    $worker = new CommandWorker(
        bus: $bus,
        serializer: $serializer,
        transport: $transport,
        commands: new PolicyTransportWorkerMetadata(),
    );

    expect($worker->processOne())->toBeTrue()
        ->and($bus->commands)->toBe([])
        ->and($policy->actors)->toBe([])
        ->and($repository->requested)->toBe([$actor->uuid->toString()])
        ->and($transport->acknowledged)->toBe([])
        ->and($transport->rejected)->toBe([$envelope]);
});
