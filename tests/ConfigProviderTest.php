<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\Command\Factory\PolicyMiddlewareFactory as CommandPolicyMiddlewareFactory;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware as CommandPolicyMiddleware;
use Componenta\CQRS\Policy\ConfigProvider;
use Componenta\CQRS\Query\Factory\PolicyMiddlewareFactory as QueryPolicyMiddlewareFactory;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware as QueryPolicyMiddleware;

it('registers policy middleware factories', function (): void {
    $config = (new ConfigProvider())();
    $factories = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES];

    expect($factories)->toMatchArray([
        CommandPolicyMiddleware::class => CommandPolicyMiddlewareFactory::class,
        QueryPolicyMiddleware::class => QueryPolicyMiddlewareFactory::class,
    ]);
});
