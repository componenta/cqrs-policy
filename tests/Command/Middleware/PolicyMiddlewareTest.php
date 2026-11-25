<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\CQRS\Tests\Fixture\GuardedPermission;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;
use Componenta\Policy\Context\ContextInterface;
use Componenta\Policy\Exception\AccessDeniedException;
use Componenta\Policy\Exception\DenyReason;
use Componenta\Policy\MissingPolicyBehavior;
use Componenta\Policy\Policies\PermissionPolicy;
use Componenta\Policy\PolicyEnforcer;
use Componenta\Policy\PolicyInterface;
use Componenta\Policy\Provider\ArrayPolicyProvider;

final readonly class CommandPolicyPlainCommand
{
    public function __construct(public string $tag = 'plain') {}
}

final readonly class CommandPolicyActorAwareCommand implements ActorAwareInterface
{
    public function __construct(public object $actor) {}
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

it('accepts an explicitly carried guest as the command actor', function (): void {
    $guest = new Guest();
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

    $middleware->execute(
        Operation::create(new CommandPolicyActorAwareCommand($guest)),
        commandPolicyTerminal(),
    );

    expect($capturedActor)->toBe($guest);
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

    expect($capturedActor)->toBeInstanceOf(Guest::class)
        ->and($capturedActor)->not->toBe($contextActor);
});

it('denies a protected non-actor-aware command as Guest', function (): void {
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([
        CommandPolicyPlainCommand::class => new PermissionPolicy(
            new GuardedPermission('posts.update.any'),
        ),
    ]));

    expect(fn() => $middleware->execute(
        Operation::create(new CommandPolicyPlainCommand()),
        commandPolicyTerminal(),
    ))->toThrow(AccessDeniedException::class);
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
        PolicyMiddleware::ATTR_POLICY_CONTEXT => ['source' => 'test'],
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
        PolicyMiddleware::ATTR_POLICY_CONTEXT => 'invalid',
    ]);

    expect(fn() => $middleware->execute($operation, commandPolicyTerminal()))
        ->toThrow(InvalidArgumentException::class, 'must be an array');
});

it('never bypasses denied command policies for non-boolean or false skip flags', function (mixed $flag): void {
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([]));
    $operation = Operation::create(new CommandPolicyPlainCommand(), [
        PolicyMiddleware::ATTR_SKIP_POLICY => $flag,
    ]);
    $handler = new class implements OperationHandlerInterface {
        public int $calls = 0;
        public function handle(OperationInterface $operation): OperationInterface
        {
            ++$this->calls;
            return $operation;
        }
    };

    expect(fn () => $middleware->execute($operation, $handler))
        ->toThrow(AccessDeniedException::class);
    expect($handler->calls)->toBe(0);
})->with([false, null, 0, 1, '1', 'true', ['truthy'], new stdClass()]);

it('replaces spoofed command and operation policy context with the actual dispatch objects', function (bool $objectContext): void {
    $captured = null;
    $policy = new class($captured) implements PolicyInterface {
        public function __construct(public ?ContextInterface &$captured) {}
        public function enforce(object $actor, ContextInterface $context): true|DenyReason
        {
            $this->captured = $context;
            return true;
        }
    };
    $command = new CommandPolicyPlainCommand();
    $spoofed = new stdClass();
    $attributes = [
        'tenant' => 'original',
        PolicyMiddleware::ATTR_COMMAND => $spoofed,
        PolicyMiddleware::ATTR_OPERATION => $spoofed,
    ];
    $context = $objectContext
        ? new \Componenta\Policy\Context\Context($attributes)
        : $attributes;
    $operation = Operation::create($command, [PolicyMiddleware::ATTR_POLICY_CONTEXT => $context]);
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([CommandPolicyPlainCommand::class => $policy]));
    $result = $middleware->execute($operation, commandPolicyTerminal());

    expect($result->result?->value)->toBe('handled')
        ->and($captured?->getAttribute('tenant'))->toBe('original')
        ->and($captured?->getAttribute(PolicyMiddleware::ATTR_COMMAND))->toBe($command)
        ->and($captured?->getAttribute(PolicyMiddleware::ATTR_OPERATION))->toBe($operation);
    if ($objectContext) {
        expect($context->getAttribute(PolicyMiddleware::ATTR_COMMAND))->toBe($spoofed)
            ->and($context->getAttribute(PolicyMiddleware::ATTR_OPERATION))->toBe($spoofed);
    } else {
        expect($operation->attributes[PolicyMiddleware::ATTR_POLICY_CONTEXT])->toBe($attributes);
    }
})->with([false, true]);


it('resolves an explicit action identifier instead of the command class for policy selection', function (): void {
    $command = new readonly class implements \Componenta\Policy\ActionIdAwareInterface {
        public function __construct(public string $actionId = 'posts.publish') {}
    };
    $operation = Operation::create($command);
    $middleware = new PolicyMiddleware(makeCommandPolicyEnforcer([
        $command::class => new \Componenta\Policy\Policies\Deny('class policy must not be selected'),
        'posts.publish' => new \Componenta\Policy\Policies\Allow(),
    ]));

    expect($middleware->execute($operation, commandPolicyTerminal())->result?->value)->toBe('handled');
    $denied = new PolicyMiddleware(makeCommandPolicyEnforcer([
        $command::class => new \Componenta\Policy\Policies\Allow(),
        'posts.publish' => new \Componenta\Policy\Policies\Deny('explicit action denied'),
    ]));
    $handler = $this->createMock(OperationHandlerInterface::class);
    $handler->expects($this->never())->method('handle');
    expect(fn () => $denied->execute($operation, $handler))->toThrow(AccessDeniedException::class);
});
