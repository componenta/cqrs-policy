<?php

declare(strict_types=1);

namespace Componenta\CQRS\Query\Factory;

use Componenta\CQRS\Query\Middleware\PolicyMiddleware;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\Policy\Actor\ActorProviderInterface;
use Componenta\Policy\PolicyEnforcer;
use LogicException;
use Psr\Container\ContainerInterface;

final class PolicyMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): PolicyMiddleware
    {
        $enforcer = $container->get(PolicyEnforcer::class);

        if (!$enforcer instanceof PolicyEnforcer) {
            throw new LogicException(sprintf(
                'Container entry "%s" must be a %s instance.',
                PolicyEnforcer::class,
                PolicyEnforcer::class,
            ));
        }

        $actorProvider = null;
        if ($container->has(ActorProviderInterface::class)) {
            $actorProvider = $container->get(ActorProviderInterface::class);

            if (!$actorProvider instanceof ActorProviderInterface) {
                throw new LogicException(sprintf(
                    'Container entry "%s" must implement %s.',
                    ActorProviderInterface::class,
                    ActorProviderInterface::class,
                ));
            }
        }

        $resolver = new ActionIdResolver();
        if ($container->has(ActionIdResolverInterface::class)) {
            $resolver = $container->get(ActionIdResolverInterface::class);

            if (!$resolver instanceof ActionIdResolverInterface) {
                throw new LogicException(sprintf(
                    'Container entry "%s" must implement %s.',
                    ActionIdResolverInterface::class,
                    ActionIdResolverInterface::class,
                ));
            }
        }

        return new PolicyMiddleware($enforcer, $actorProvider, $resolver);
    }
}
