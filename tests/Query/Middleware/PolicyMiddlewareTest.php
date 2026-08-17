<?php

declare(strict_types=1);

use Componenta\CQRS\Query\Context\Context;
use Componenta\CQRS\Query\Exception\AuthenticationRequiredException;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\CQRS\Tests\Fixture\FakeActorAwareQuery;
use Componenta\CQRS\Tests\Fixture\FakeActorProvider;
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

it('extracts actor from ActorAwareInterface query and invokes next on allow', function (): void {
    $actor = new FakeActor(42);
    $query = new FakeActorAwareQuery($actor);
    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeActorAwareQuery::class => new Allow(),
    ]));

    expect($middleware->handle($query, new Context(), static fn() => 'result'))
        ->toBe('result');
});

it('falls back to ActorProvider when query is not ActorAware', function (): void {
    $actor = new FakeActor(1);
    $middleware = new PolicyMiddleware(
        makeEnforcer([FakeQuery::class => new Allow()]),
        new FakeActorProvider($actor),
    );

    expect($middleware->handle(new FakeQuery(), new Context(), static fn() => 'ok'))
        ->toBe('ok');
});

it('accepts Guest when the provider explicitly represents anonymous access', function (): void {
    $guest = new Guest();
    $middleware = new PolicyMiddleware(
        makeEnforcer([FakeQuery::class => new Allow()]),
        new FakeActorProvider($guest),
    );

    expect($middleware->handle(new FakeQuery(), new Context(), static fn() => 'public'))
        ->toBe('public');
});

it('treats provider null as absence of an actor rather than anonymous Guest', function (): void {
    $middleware = new PolicyMiddleware(
        makeEnforcer([FakeQuery::class => new Allow()]),
        new FakeActorProvider(null),
    );

    expect(fn() => $middleware->handle(new FakeQuery(), new Context(), static fn() => 'ok'))
        ->toThrow(AuthenticationRequiredException::class);
});

it('throws when query carries no actor and no provider is configured', function (): void {
    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeQuery::class => new Allow(),
    ]));

    expect(fn() => $middleware->handle(new FakeQuery(), new Context(), static fn() => 'ok'))
        ->toThrow(AuthenticationRequiredException::class);
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
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $middleware = new PolicyMiddleware(makeEnforcer([]));

    expect(fn() => $middleware->handle($query, new Context(), static fn() => 'x'))
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

it('ATTR_ACTOR overrides query and provider actors for one call', function (): void {
    $override = new FakeActor(999);
    $query = new FakeActorAwareQuery(new FakeActor(1));
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

    $middleware->handle(
        $query,
        new Context([PolicyMiddleware::ATTR_ACTOR => $override]),
        static fn() => 'ok',
    );

    expect($capturedActor)->toBe($override);
});

it('rejects a non-object actor override', function (): void {
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $middleware = new PolicyMiddleware(makeEnforcer([
        FakeActorAwareQuery::class => new Allow(),
    ]));

    expect(fn() => $middleware->handle(
        $query,
        new Context([PolicyMiddleware::ATTR_ACTOR => 'not-an-object']),
        static fn() => 'ok',
    ))->toThrow(AuthenticationRequiredException::class);
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
