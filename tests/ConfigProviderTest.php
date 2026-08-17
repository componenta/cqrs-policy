<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\Command\Factory\PolicyMiddlewareFactory as CommandPolicyMiddlewareFactory;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware as CommandPolicyMiddleware;
use Componenta\CQRS\Policy\ConfigProvider;
use Componenta\CQRS\Query\Factory\PolicyMiddlewareFactory as QueryPolicyMiddlewareFactory;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware as QueryPolicyMiddleware;
use Componenta\CQRS\Resolver\ActorResolver;

it('registers policy middleware factories and the actor resolver', function (): void {
    $config = (new ConfigProvider())();
    $dependencies = $config[DependencyConfigKey::DEPENDENCIES];

    expect($dependencies[DependencyConfigKey::FACTORIES])->toMatchArray([
        CommandPolicyMiddleware::class => CommandPolicyMiddlewareFactory::class,
        QueryPolicyMiddleware::class => QueryPolicyMiddlewareFactory::class,
    ])->and($dependencies[DependencyConfigKey::INVOKABLES])
        ->toContain(ActorResolver::class);
});
