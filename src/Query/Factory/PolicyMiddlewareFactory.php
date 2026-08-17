<?php

declare(strict_types=1);

namespace Componenta\CQRS\Query\Factory;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\Query\Middleware\PolicyMiddleware;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\Policy\Actor\ActorProviderInterface;
use Componenta\Policy\PolicyEnforcer;

final class PolicyMiddlewareFactory
{
    public function __invoke(ContainerValue $container): PolicyMiddleware
    {
        $actorProvider = $container->has(ActorProviderInterface::class)
            ? $container->get(ActorProviderInterface::class, ActorProviderInterface::class)
            : null;

        $resolver = $container->has(ActionIdResolverInterface::class)
            ? $container->get(ActionIdResolverInterface::class, ActionIdResolverInterface::class)
            : new ActionIdResolver();

        return new PolicyMiddleware(
            enforcer: $container->get(PolicyEnforcer::class, PolicyEnforcer::class),
            actorProvider: $actorProvider,
            resolver: $resolver,
        );
    }
}
