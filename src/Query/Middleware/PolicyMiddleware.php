<?php

declare(strict_types=1);

namespace Componenta\CQRS\Query\Middleware;

use Componenta\CQRS\Command\Exception\AuthenticationRequiredException;
use Componenta\CQRS\Query\Context\ContextInterface;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorProviderInterface;
use Componenta\Policy\Context\ContextInterface as PolicyContextInterface;
use Componenta\Policy\PolicyEnforcer;

/**
 * Middleware that enforces policy checks on queries.
 *
 * Uses {@see PolicyEnforcer} to check authorization based on policy attributes
 * defined on query classes. The action ID is the query's FQCN.
 *
 * Actor resolution priority:
 *  1. Per-call context key {@see self::ATTR_ACTOR}.
 *  2. {@see ActorAwareInterface} - actor embedded in the query itself.
 *  3. {@see ActorProviderInterface} - global actor resolution (e.g., from session).
 *
 * Public queries should normally use an explicit `#[Allow]` policy. Per-call
 * opt-out via {@see self::ATTR_SKIP_POLICY} is reserved for technical flows
 * where authorization already happened earlier or cannot be evaluated in the
 * current process.
 *
 * Symmetric with {@see \Componenta\CQRS\Command\Middleware\PolicyMiddleware} on the
 * command side - the same `#[PermissionPolicy]`, `#[OneOf]`, `#[AllOf]` work
 * on both commands and queries.
 *
 * @example Protected query - requires the permission, actor resolved from request
 * ```php
 * use Componenta\Policy\Actor\ActorInterface;
 * use Componenta\Policy\Permission\Permission;
 * use Componenta\Policy\Policies\PermissionPolicy;
 *
 * #[PermissionPolicy(new Permission('comments.moderate'))]
 * final readonly class GetAdminComments implements ActorAwareInterface
 * {
 *     public function __construct(
 *         public ActorInterface $actor,
 *         public ?string $status = null,
 *     ) {}
 * }
 * ```
 *
 * @example Public query - explicitly allowed by policy
 * ```php
 * use Componenta\CQRS\Query\QueryBusInterface;
 * use Componenta\Policy\Policies\Allow;
 *
 * #[Allow]
 * final readonly class GetPublicPosts {}
 *
 * public function __invoke(QueryBusInterface $bus): mixed
 * {
 *     return $bus->handle(new GetPublicPosts());
 * }
 * ```
 */
final readonly class PolicyMiddleware implements MiddlewareInterface
{
    /**
     * Context key that skips the policy check for a single dispatch call.
     * Intended for public controllers only.
     */
    public const string ATTR_SKIP_POLICY = '__skip_policy';

    /**
     * Context key that overrides the actor for a single dispatch call.
     * Takes priority over `ActorAwareInterface` and `ActorProviderInterface`.
     */
    public const string ATTR_ACTOR = '__actor';

    /**
     * Context key that overrides the policy-level context passed to the enforcer.
     * Value must be a {@see ContextInterface} or array.
     */
    public const string ATTR_POLICY_CONTEXT = '__policy_context';

    /**
     * Policy-context key that exposes the query to contextual policies.
     */
    public const string ATTR_QUERY = '__query';

    public function __construct(
        private PolicyEnforcer $enforcer,
        private ?ActorProviderInterface $actorProvider = null,
        private ActionIdResolverInterface $resolver = new ActionIdResolver,
    ) {}

    public function handle(object $query, ContextInterface $context, callable $next): mixed
    {
        if ($context->getAttribute(self::ATTR_SKIP_POLICY) === true) {
            return $next($query, $context);
        }

        $actionId = $this->resolver->resolve($query);

        $actor = $this->resolveActor($query, $context, $actionId);

        $this->enforcer->enforce($actionId, $actor, $this->resolvePolicyContext($query, $context));

        return $next($query, $context);
    }

    /**
     * @throws AuthenticationRequiredException If no actor can be resolved.
     */
    private function resolveActor(object $query, ContextInterface $context, string $actionId): object
    {
        if ($context->hasAttribute(self::ATTR_ACTOR)) {
            $actor = $context->getAttribute(self::ATTR_ACTOR);

            if (!is_object($actor)) {
                throw new AuthenticationRequiredException(
                    $actionId,
                    sprintf(
                        "Invalid actor type in context: expected object, got '%s'",
                        get_debug_type($actor),
                    ),
                );
            }

            return $actor;
        }

        if ($query instanceof ActorAwareInterface) {
            return $query->actor;
        }

        if ($this->actorProvider !== null) {
            $actor = $this->actorProvider->getActor();

            if ($actor !== null) {
                return $actor;
            }

            throw new AuthenticationRequiredException(
                $actionId,
                'ActorProvider returned null - user may not be authenticated',
            );
        }

        throw new AuthenticationRequiredException(
            $actionId,
            'Query does not embed an actor and no ActorProvider is configured',
        );
    }

    /**
     * @return PolicyContextInterface|array<string, mixed>
     */
    private function resolvePolicyContext(object $query, ContextInterface $context): PolicyContextInterface|array
    {
        $policyContext = $context->getAttribute(self::ATTR_POLICY_CONTEXT);

        if ($policyContext instanceof PolicyContextInterface) {
            return $policyContext->withAttribute(self::ATTR_QUERY, $query);
        }

        if (is_array($policyContext)) {
            $policyContext[self::ATTR_QUERY] = $query;

            return $policyContext;
        }

        return [self::ATTR_QUERY => $query];
    }
}
