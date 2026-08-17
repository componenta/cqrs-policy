<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\CQRS\Query\Factory\PolicyMiddlewareFactory;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\CQRS\Tests\Fixture\FakeActorProvider;
use Componenta\CQRS\Tests\Fixture\FakeContainer;
use Componenta\Policy\Actor\ActorProviderInterface;
use Componenta\Policy\MissingPolicyBehavior;
use Componenta\Policy\PolicyEnforcer;
use Componenta\Policy\Provider\ArrayPolicyProvider;

it('builds query policy middleware from typed ContainerValue entries', function (): void {
    $entries = [];
    $provider = new FakeActorProvider(null);
    $enforcer = new PolicyEnforcer(
        provider: new ArrayPolicyProvider(new FakeContainer(), []),
        behavior: MissingPolicyBehavior::DENY,
    );
    $resolver = new class implements ActionIdResolverInterface {
        public function resolve(object $subject): string
        {
            return $subject::class;
        }
    };

    $entries[PolicyEnforcer::class] = $enforcer;
    $entries[ActorProviderInterface::class] = $provider;
    $entries[ActionIdResolverInterface::class] = $resolver;

    $middleware = (new PolicyMiddlewareFactory())(new ContainerValue(
        new FakeContainer($entries),
        new Config([]),
    ));

    expect($middleware)->toBeInstanceOf(PolicyMiddleware::class);
});
