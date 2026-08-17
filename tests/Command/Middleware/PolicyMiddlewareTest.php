<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorInterface;
use Componenta\Policy\Actor\Guest;
use Componenta\Policy\Context\ContextInterface;
use Componenta\Policy\Exception\DenyReason;
use Componenta\Policy\MissingPolicyBehavior;
use Componenta\Policy\PolicyEnforcer;
use Componenta\Policy\PolicyInterface;
use Componenta\Policy\Provider\ArrayPolicyProvider;

final readonly class CommandPolicyPlainCommand
{
    public function __construct(public string $tag = 'plain') {}
}

final readonly class CommandPolicyActorAwareCommand implements ActorAwareInterface
{
    public function __construct(
        public ActorInterface $actor,
    ) {}
}

function makeCommandPolicyEnforcer(
    array $policies,
    MissingPolicyBehavior $behavior = MissingPolicyBehavior::DENY,
): PolicyEnforcer {
    return new PolicyEnforcer(
        provider: new ArrayPolicyProvider(new FakeContainer(), $policies),
        behavior: $behavior,
    );
}

function commandPolicyTerminal(): OperationHandlerInterface
{
    return new readonly class implements OperationHandlerInterface {
        public function handle(OperationInterface $operation): OperationInterface
        {
            return $operation->withResult(new OperationResult('handled'));
        }
    };
}

it('skips command policy check when ATTR_SKIP_POLICY is true', function (): void {
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([]));
    $operation = Operation::create(new CommandPolicyPlainCommand(), [
        PolicyMiddleware::ATTR_SKIP_POLICY => true,
    ]);

    $result = $middleware->execute($operation, commandPolicyTerminal());

    expect($result->result?->value)->toBe('handled');
});

it('uses only the actor explicitly carried by an actor-aware command', function (): void {
    $commandActor = new FakeActor(1);
    $contextActor = new FakeActor(2);
    $capturedActor = null;

    $policy = new class($capturedActor) implements PolicyInterface {
        public function __construct(public ?object &$capturedActor) {}

        public function enforce(object $actor, ContextInterface $context): true|DenyReason
        {
            $this->capturedActor = $actor;

            return true;
        }
    };

    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([
        CommandPolicyActorAwareCommand::class => $policy,
    ]));
    $operation = Operation::create(
        new CommandPolicyActorAwareCommand($commandActor),
        ['__actor' => $contextActor],
    );

    $middleware->execute($operation, commandPolicyTerminal());

    expect($capturedActor)->toBe($commandActor);
});

it('treats a non-actor-aware command as anonymous and ignores actor-shaped attributes', function (): void {
    $contextActor = new FakeActor(2);
    $capturedActor = null;

    $policy = new class($capturedActor) implements PolicyInterface {
        public function __construct(public ?object &$capturedActor) {}

        public function enforce(object $actor, ContextInterface $context): true|DenyReason
        {
            $this->capturedActor = $actor;

            return true;
        }
    };

    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([
        CommandPolicyPlainCommand::class => $policy,
    ]));
    $operation = Operation::create(
        new CommandPolicyPlainCommand(),
        ['__actor' => $contextActor],
    );

    $middleware->execute($operation, commandPolicyTerminal());

    expect($capturedActor)->toBeInstanceOf(Guest::class);
});

it('adds command and operation to array policy context', function (): void {
    $actor = new FakeActor(1);
    $capturedContext = null;

    $policy = new class($capturedContext) implements PolicyInterface {
        public function __construct(public ?ContextInterface &$capturedContext) {}

        public function enforce(object $actor, ContextInterface $context): true|DenyReason
        {
            $this->capturedContext = $context;

            return true;
        }
    };

    $command = new CommandPolicyActorAwareCommand($actor);
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([
        CommandPolicyActorAwareCommand::class => $policy,
    ]));
    $operation = Operation::create($command, [
        PolicyMiddleware::ATTR_CONTEXT => ['source' => 'test'],
    ]);

    $middleware->execute($operation, commandPolicyTerminal());

    expect($capturedContext?->getAttribute('source'))->toBe('test')
        ->and($capturedContext?->getAttribute(PolicyMiddleware::ATTR_COMMAND))->toBe($command)
        ->and($capturedContext?->getAttribute(PolicyMiddleware::ATTR_OPERATION))->toBe($operation);
});

it('rejects an invalid command policy context value', function (): void {
    $command = new CommandPolicyActorAwareCommand(new FakeActor(1));
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([
        CommandPolicyActorAwareCommand::class => new Componenta\Policy\Policies\Allow(),
    ]));
    $operation = Operation::create($command, [
        PolicyMiddleware::ATTR_CONTEXT => 'invalid',
    ]);

    expect(fn() => $middleware->execute($operation, commandPolicyTerminal()))
        ->toThrow(InvalidArgumentException::class, 'must be an array');
});
