<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Factory;

use Componenta\Config\ContainerValue;
use Componenta\CQRS\Command\Middleware\PolicyMiddleware;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\CQRS\Resolver\ActorResolver;
use Componenta\Policy\PolicyEnforcer;

final class PolicyMiddlewareFactory
{
    public function __invoke(ContainerValue $container): PolicyMiddleware
    {
        $resolver = $container->has(ActionIdResolverInterface::class)
            ? $container->get(
                ActionIdResolverInterface::class,
                ActionIdResolverInterface::class,
            )
            : new ActionIdResolver();

        return new PolicyMiddleware(
            enforcer: $container->get(
                PolicyEnforcer::class,
                PolicyEnforcer::class,
            ),
            actors: $container->get(
                ActorResolver::class,
                ActorResolver::class,
            ),
            resolver: $resolver,
        );
    }
}
