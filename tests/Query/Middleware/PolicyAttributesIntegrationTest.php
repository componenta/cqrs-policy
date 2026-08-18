<?php

declare(strict_types=1);

use Componenta\CQRS\Query\Context\Context;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware;
use Componenta\CQRS\Tests\Fixture\FakeActor;
use Componenta\CQRS\Tests\Fixture\GuardedPermission;
use Componenta\CQRS\Tests\Fixture\GuardedQueryWithOneOf;
use Componenta\CQRS\Tests\Fixture\GuardedQueryWithPermission;
use Componenta\DI\FactoryInterface;
use Componenta\Policy\Exception\AccessDeniedException;
use Componenta\Policy\Permission\PermissionCollection;
use Componenta\Policy\PolicyEnforcer;
use Componenta\Policy\Provider\AttributePolicyProvider;

function enforcerWithAttributes(): PolicyEnforcer
{
    $factory = new class implements FactoryInterface {
        public function make(string $entry, array $params = []): object
        {
            return new $entry(...$params);
        }
    };

    return new PolicyEnforcer(new AttributePolicyProvider($factory));
}

it('discovers #[PermissionPolicy] attribute on a query class and allows when actor holds the permission', function () {
    $actor = new FakeActor(1, new PermissionCollection([new GuardedPermission('posts.view.any')]));
    $query = new GuardedQueryWithPermission($actor);

    $middleware = new PolicyMiddleware(enforcerWithAttributes());

    $result = $middleware->handle($query, new Context(), static fn() => 'allowed');

    expect($result)->toBe('allowed');
});

it('denies via #[PermissionPolicy] when actor does not hold the permission', function () {
    $actor = new FakeActor(1, new PermissionCollection([new GuardedPermission('unrelated')]));
    $query = new GuardedQueryWithPermission($actor);

    $middleware = new PolicyMiddleware(enforcerWithAttributes());

    expect(fn() => $middleware->handle($query, new Context(), static fn() => 'x'))
        ->toThrow(AccessDeniedException::class);
});

it('discovers #[OneOf] attribute on a query class and allows when actor satisfies any branch', function () {
    $actor = new FakeActor(1, new PermissionCollection([new GuardedPermission('posts.edit.any')]));
    $query = new GuardedQueryWithOneOf($actor);

    $middleware = new PolicyMiddleware(enforcerWithAttributes());

    $result = $middleware->handle($query, new Context(), static fn() => 'allowed');

    expect($result)->toBe('allowed');
});

it('denies #[OneOf] when actor satisfies no branch', function () {
    $actor = new FakeActor(1, new PermissionCollection([new GuardedPermission('unrelated')]));
    $query = new GuardedQueryWithOneOf($actor);

    $middleware = new PolicyMiddleware(enforcerWithAttributes());

    expect(fn() => $middleware->handle($query, new Context(), static fn() => 'x'))
        ->toThrow(AccessDeniedException::class);
});
