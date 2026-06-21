<?php

declare(strict_types=1);

namespace Componenta\CQRS\Query\Factory;

use Componenta\CQRS\Query\Middleware\PolicyMiddleware;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\Policy\Actor\ActorProviderInterface;
use Componenta\Policy\PolicyEnforcer;
use Psr\Container\ContainerInterface;

final class PolicyMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): PolicyMiddleware
    {
        return new PolicyMiddleware(
            $container->get(PolicyEnforcer::class),
            $container->has(ActorProviderInterface::class)
                ? $container->get(ActorProviderInterface::class)
                : null,
            $container->has(ActionIdResolverInterface::class) ?
                $container->get(ActionIdResolverInterface::class) : new ActionIdResolver,
        );
    }
}
