<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Exception\AuthenticationRequiredException;
use Componenta\CQRS\Query\Context\Context;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\CQRS\Tests\Fixture\FakeActorAwareQuery;
use Componenta\CQRS\Tests\Fixture\FakeActorProvider;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\CQRS\Tests\Fixture\FakeQuery;
use Componenta\Policy\Exception\AccessDeniedException;
use Componenta\Policy\MissingPolicyBehavior;
use Componenta\Policy\Policies\Allow;
use Componenta\Policy\Policies\Deny;
use Componenta\Policy\PolicyEnforcer;
use Componenta\Policy\Provider\ArrayPolicyProvider;

function makeEnforcer(array $policies, MissingPolicyBehavior $behavior = MissingPolicyBehavior::DENY): PolicyEnforcer {
    return new PolicyEnforcer(
        provider: new ArrayPolicyProvider(new FakeContainer(), $policies),
        behavior: $behavior,
    );
}

it('extracts actor from ActorAwareInterface query and invokes next on allow', function () {
    $actor = new FakeActor(42);
    $query = new FakeActorAwareQuery($actor);

    $enforcer = makeEnforcer([FakeActorAwareQuery::class => new Allow()]);
    $middleware = new PolicyMiddleware($enforcer);

    $called = false;
    $result = $middleware->handle($query, new Context(), function ($q) use ($query, &$called) {
        expect($q)->toBe($query);
        $called = true;
        return 'result';
    });

    expect($called)->toBeTrue()->and($result)->toBe('result');
});

it('falls back to ActorProvider when query is not ActorAware', function () {
    $actor = new FakeActor(1);
    $query = new FakeQuery();

    $enforcer = makeEnforcer([FakeQuery::class => new Allow()]);
    $middleware = new PolicyMiddleware($enforcer, new FakeActorProvider($actor));

    $result = $middleware->handle($query, new Context(), static fn() => 'ok');

    expect($result)->toBe('ok');
});

it('throws AuthenticationRequiredException when ActorProvider returns null', function () {
    $enforcer = makeEnforcer([FakeQuery::class => new Allow()]);
    $middleware = new PolicyMiddleware($enforcer, new FakeActorProvider(null));

    expect(fn() => $middleware->handle(new FakeQuery(), new Context(), static fn() => 'ok'))
        ->toThrow(AuthenticationRequiredException::class);
});

it('throws AuthenticationRequiredException when query is not ActorAware and no provider is configured', function () {
    $enforcer = makeEnforcer([FakeQuery::class => new Allow()]);
    $middleware = new PolicyMiddleware($enforcer);

    expect(fn() => $middleware->handle(new FakeQuery(), new Context(), static fn() => 'ok'))
        ->toThrow(AuthenticationRequiredException::class);
});

it('propagates AccessDeniedException when the enforcer denies', function () {
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $enforcer = makeEnforcer([FakeActorAwareQuery::class => new Deny('nope')]);
    $middleware = new PolicyMiddleware($enforcer);

    $called = false;
    expect(fn() => $middleware->handle($query, new Context(), function () use (&$called) { $called = true; return 'x'; }))
        ->toThrow(AccessDeniedException::class);

    expect($called)->toBeFalse();
});

it('denies an action with no policy registered under DENY behavior', function () {
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $enforcer = makeEnforcer([]); // default DENY
    $middleware = new PolicyMiddleware($enforcer);

    expect(fn() => $middleware->handle($query, new Context(), static fn() => 'x'))
        ->toThrow(AccessDeniedException::class);
});

it('skips the policy check entirely when ATTR_SKIP_POLICY is set in context', function () {
    $query = new FakeQuery();
    $enforcer = makeEnforcer([]); // DENY default
    $middleware = new PolicyMiddleware($enforcer);

    $result = $middleware->handle(
        $query,
        new Context([PolicyMiddleware::ATTR_SKIP_POLICY => true]),
        static fn($q) => 'public result',
    );

    expect($result)->toBe('public result');
});

it('ATTR_SKIP_POLICY also bypasses an otherwise-denying policy', function () {
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $enforcer = makeEnforcer([FakeActorAwareQuery::class => new Deny('no')]);
    $middleware = new PolicyMiddleware($enforcer);

    $result = $middleware->handle(
        $query,
        new Context([PolicyMiddleware::ATTR_SKIP_POLICY => true]),
        static fn() => 'ok',
    );

    expect($result)->toBe('ok');
});

it('ATTR_ACTOR overrides the actor from query/provider', function () {
    $override = new FakeActor(999);
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $capturedActor = null;

    $capturingPolicy = new class($capturedActor) implements Componenta\Policy\PolicyInterface {
        public function __construct(public ?object &$captured) {}
        public function enforce(object $actor, Componenta\Policy\Context\ContextInterface $context): true|Componenta\Policy\Exception\DenyReason {
            $this->captured = $actor;
            return true;
        }
    };

    $enforcer = makeEnforcer([FakeActorAwareQuery::class => $capturingPolicy]);
    $middleware = new PolicyMiddleware($enforcer);

    $middleware->handle($query, new Context([PolicyMiddleware::ATTR_ACTOR => $override]), static fn() => 'ok');

    expect($capturedActor)->toBe($override);
});

it('throws when ATTR_ACTOR carries a non-object value', function () {
    $query = new FakeActorAwareQuery(new FakeActor(1));
    $enforcer = makeEnforcer([FakeActorAwareQuery::class => new Allow()]);
    $middleware = new PolicyMiddleware($enforcer);

    expect(fn() => $middleware->handle($query, new Context([PolicyMiddleware::ATTR_ACTOR => 'not-an-object']), static fn() => 'ok'))
        ->toThrow(AuthenticationRequiredException::class);
});

it('passes the query into the policy context under ATTR_QUERY', function () {
    $query = new FakeActorAwareQuery(new FakeActor(1));

    $capturingPolicy = new class implements Componenta\Policy\PolicyInterface {
        public ?Componenta\Policy\Context\ContextInterface $context = null;

        public function enforce(object $actor, Componenta\Policy\Context\ContextInterface $context): true|Componenta\Policy\Exception\DenyReason
        {
            $this->context = $context;
            return true;
        }
    };

    $enforcer = makeEnforcer([FakeActorAwareQuery::class => $capturingPolicy]);
    $middleware = new PolicyMiddleware($enforcer);

    $middleware->handle($query, new Context(), static fn() => 'ok');

    expect($capturingPolicy->context?->getAttribute(PolicyMiddleware::ATTR_QUERY))->toBe($query);
});
