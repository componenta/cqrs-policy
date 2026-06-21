<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Exception\AuthenticationRequiredException;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorInterface;
use Componenta\Policy\Context\ContextInterface;
use Componenta\Policy\Exception\DenyReason;
use Componenta\Policy\MissingPolicyBehavior;
use Componenta\Policy\Policies\AlwaysPolicy;
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

it('skips command policy check when ATTR_SKIP_POLICY is true', function () {
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([]));
    $operation = Operation::create(new CommandPolicyPlainCommand(), [
        PolicyMiddleware::ATTR_SKIP_POLICY => true,
    ]);

    $result = $middleware->execute($operation, commandPolicyTerminal());

    expect($result->result?->value)->toBe('handled');
});

it('does not skip command policy check when ATTR_SKIP_POLICY is false', function () {
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([]));
    $operation = Operation::create(new CommandPolicyPlainCommand(), [
        PolicyMiddleware::ATTR_SKIP_POLICY => false,
    ]);

    expect(fn () => $middleware->execute($operation, commandPolicyTerminal()))
        ->toThrow(AuthenticationRequiredException::class);
});

it('uses ATTR_ACTOR before the command actor', function () {
    $commandActor = new FakeActor(1);
    $overrideActor = new FakeActor(2);
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
    $operation = Operation::create(new CommandPolicyActorAwareCommand($commandActor), [
        PolicyMiddleware::ATTR_ACTOR => $overrideActor,
    ]);

    $middleware->execute($operation, commandPolicyTerminal());

    expect($capturedActor)->toBe($overrideActor);
});

it('adds command and operation to array policy context', function () {
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
