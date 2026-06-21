<?php

declare(strict_types=1);

namespace Componenta\CQRS\Policy;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware as CommandPolicyMiddleware;
use Componenta\CQRS\Query\Factory\PolicyMiddlewareFactory as QueryPolicyMiddlewareFactory;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware as QueryPolicyMiddleware;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            CommandPolicyMiddleware::class => \Componenta\CQRS\Command\Factory\PolicyMiddlewareFactory::class,
            QueryPolicyMiddleware::class => QueryPolicyMiddlewareFactory::class,
        ];
    }
}
