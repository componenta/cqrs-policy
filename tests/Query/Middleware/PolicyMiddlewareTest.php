<?php

declare(strict_types=1);

use Componenta\CQRS\Query\Context\Context;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\CQRS\Tests\Fixture\FakeActorAwareQuery;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\CQRS\Tests\Fixture\FakeQuery;
use Componenta\Policy\Actor\Guest;
use Componenta\Policy\Exception\AccessDeniedException;
use Componenta\Policy\MissingPolicyBehavior;
use Componenta\Policy\Policies\Allow;
use Componenta\Policy\Policies\Deny;
use Componenta\Policy\PolicyEnforcer;
use Componenta\Policy\Provider\ArrayPolicyProvider;

function makeEnforcer(array $policies, MissingPolicyBehavior $behavior = MissingPolicyBehavior::DENY): PolicyEnforcer
{
    return new PolicyEnforcer(
        provider: new ArrayPolicyProvider(new FakeContainer(), $policies),
        behavior: $behavior,
    );
}

it('uses the actor explicitly carried by an ActorAware query', function (): void {
    $actor = new FakeActor(42);
    $query = new FakeActorAwareQuery($actor);
    $capturedActor = null;

    $policy = new class($capturedActor) implements Componenta\Policy\PolicyInterface {
        public function __construct(public ?object &$captured) {}

        public function enforce(
            object $actor,
            Componenta\Policy\Context\ContextInterface $context,
        ): true|Componenta\Policy\Exception\DenyReason {
            $this->captured = $actor;

            return true;
        }
    };

    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeActorAwareQuery::class => $policy,
    ]));

    expect($middleware->handle($query, new Context(), static fn() => 'result'))
        ->toBe('result')
        ->and($capturedActor)->toBe($actor);
});

it('evaluates a query without ActorAwareInterface as Guest', function (): void {
    $capturedActor = null;

    $policy = new class($capturedActor) implements Componenta\Policy\PolicyInterface {
        public function __construct(public ?object &$captured) {}

        public function enforce(
            object $actor,
            Componenta\Policy\Context\ContextInterface $context,
        ): true|Componenta\Policy\Exception\DenyReason {
            $this->captured = $actor;

            return true;
        }
    };

    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeQuery::class => $policy,
    ]));

    expect($middleware->handle(new FakeQuery(), new Context(), static fn() => 'public'))
        ->toBe('public')
        ->and($capturedActor)->toBeInstanceOf(Guest::class);
});

it('allows a public query through an explicit Allow policy as Guest', function (): void {
    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeQuery::class => new Allow(),
    ]));

    expect($middleware->handle(new FakeQuery(), new Context(), static fn() => 'public'))
        ->toBe('public');
});

it('propagates AccessDeniedException when the enforcer denies', function (): void {
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeActorAwareQuery::class => new Deny('nope'),
    ]));
    $called = false;

    expect(fn() => $middleware->handle($query, new Context(), function () use (&$called) {
        $called = true;
        return 'x';
    }))->toThrow(AccessDeniedException::class);

    expect($called)->toBeFalse();
});

it('denies an action with no policy registered under DENY behavior', function (): void {
    $middleware = new PolicyMiddleware(makeEnforcer([]));

    expect(fn() => $middleware->handle(new FakeQuery(), new Context(), static fn() => 'x'))
        ->toThrow(AccessDeniedException::class);
});

it('skips policy only when the trusted technical flag is strictly true', function (): void {
    $middleware = new PolicyMiddleware(makeEnforcer([]));

    expect($middleware->handle(
        new FakeQuery(),
        new Context([PolicyMiddleware::ATTR_SKIP_POLICY => true]),
        static fn() => 'technical',
    ))->toBe('technical');
});

it('passes the query into the policy context under ATTR_QUERY', function (): void {
    $query = new FakeActorAwareQuery(new FakeActor(1));

    $policy = new class implements Componenta\Policy\PolicyInterface {
        public ?Componenta\Policy\Context\ContextInterface $context = null;

        public function enforce(
            object $actor,
            Componenta\Policy\Context\ContextInterface $context,
        ): true|Componenta\Policy\Exception\DenyReason {
            $this->context = $context;

            return true;
        }
    };

    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeActorAwareQuery::class => $policy,
    ]));

    $middleware->handle($query, new Context(), static fn() => 'ok');

    expect($policy->context?->getAttribute(PolicyMiddleware::ATTR_QUERY))->toBe($query);
});

it('rejects an invalid query policy context value', function (): void {
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeActorAwareQuery::class => new Allow(),
    ]));

    expect(fn() => $middleware->handle(
        $query,
        new Context([PolicyMiddleware::ATTR_POLICY_CONTEXT => 'invalid']),
        static fn() => 'ok',
    ))->toThrow(InvalidArgumentException::class, 'must be an array');
});
