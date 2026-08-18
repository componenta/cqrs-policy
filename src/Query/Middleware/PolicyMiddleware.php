<?php

declare(strict_types=1);

namespace Componenta\CQRS\Query\Middleware;

use Componenta\CQRS\Query\Context\ContextInterface;
use Componenta\CQRS\Query\Exception\AuthenticationRequiredException;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorProviderInterface;
use Componenta\Policy\Context\ContextInterface as PolicyContextInterface;
use Componenta\Policy\PolicyEnforcer;
use InvalidArgumentException;

/**
 * Enforces query policies against the actor resolved for the current call.
 *
 * Actor resolution priority:
 *  1. Per-call context key {@see self::ATTR_ACTOR}.
 *  2. {@see ActorAwareInterface} carried by the query.
 *  3. {@see ActorProviderInterface} supplied by the integration.
 *
 * A provider may return a concrete actor, {@see \Componenta\Policy\Actor\Guest}
 * for explicit anonymous access, or null when no actor can be resolved. Null is
 * not converted to Guest by this middleware.
 *
 * Action IDs are resolved through {@see ActionIdResolverInterface}; the default
 * resolver uses {@see \Componenta\Policy\ActionIdAwareInterface} when present
 * and otherwise falls back to the query class name.
 *
 * Public queries should use an explicit `#[Allow]` policy together with an
 * actor source that intentionally yields Guest. {@see self::ATTR_SKIP_POLICY}
 * is reserved for trusted technical flows where policy evaluation has already
 * happened or cannot be performed in the current process.
 *
 * @example Protected query carrying its actor explicitly
 * ```php
 * use Componenta\Policy\Actor\ActorAwareInterface;
 * use Componenta\Policy\Permission\Permission;
 * use Componenta\Policy\Policies\PermissionPolicy;
 *
 * #[PermissionPolicy(new Permission('comments.moderate'))]
 * final readonly class GetAdminComments implements ActorAwareInterface
 * {
 *     public function __construct(
 *         public object $actor,
 *         public ?string $status = null,
 *     ) {}
 * }
 * ```
 *
 * @example Public query when the configured ActorProvider returns Guest
 * ```php
 * use Componenta\Policy\Policies\Allow;
 *
 * #[Allow]
 * final readonly class GetPublicPosts {}
 * ```
 */
final readonly class PolicyMiddleware implements MiddlewareInterface
{
    /** Trusted technical escape hatch for one query dispatch. */
    public const string ATTR_SKIP_POLICY = '__skip_policy';

    /** Per-call actor override. Value must be an object. */
    public const string ATTR_ACTOR = '__actor';

    /** Policy-level context override. Value must be an array or policy context. */
    public const string ATTR_POLICY_CONTEXT = '__policy_context';

    /** Policy-context key exposing the current query. */
    public const string ATTR_QUERY = '__query';

    public function __construct(
        private PolicyEnforcer $enforcer,
        private ?ActorProviderInterface $actorProvider = null,
        private ActionIdResolverInterface $resolver = new ActionIdResolver(),
    ) {}

    public function handle(object $query, ContextInterface $context, callable $next): mixed
    {
        if ($context->getAttribute(self::ATTR_SKIP_POLICY) === true) {
            return $next($query, $context);
        }

        $actionId = $this->resolver->resolve($query);
        $actor = $this->resolveActor($query, $context, $actionId);

        $this->enforcer->enforce(
            $actionId,
            $actor,
            $this->resolvePolicyContext($query, $context),
        );

        return $next($query, $context);
    }

    /**
     * @throws AuthenticationRequiredException If no actor can be resolved.
     * @throws InvalidArgumentException If an explicit actor override is not an object.
     */
    private function resolveActor(object $query, ContextInterface $context, string $actionId): object
    {
        if ($context->hasAttribute(self::ATTR_ACTOR)) {
            $actor = $context->getAttribute(self::ATTR_ACTOR);

            if (!is_object($actor)) {
                throw new InvalidArgumentException(sprintf(
                    'Query actor context attribute must be an object; got %s.',
                    get_debug_type($actor),
                ));
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
                'ActorProvider returned null; no actor is available for policy evaluation',
            );
        }

        throw new AuthenticationRequiredException(
            $actionId,
            'Query does not carry an actor and no ActorProvider is configured',
        );
    }

    /** @return PolicyContextInterface|array<string, mixed> */
    private function resolvePolicyContext(object $query, ContextInterface $context): PolicyContextInterface|array
    {
        $policyContext = $context->getAttribute(self::ATTR_POLICY_CONTEXT);

        if ($policyContext instanceof PolicyContextInterface) {
            return $policyContext->withAttribute(self::ATTR_QUERY, $query);
        }

        if (is_array($policyContext)) {
            $policyContext = self::normalizeArrayContext($policyContext);
            $policyContext[self::ATTR_QUERY] = $query;

            return $policyContext;
        }

        if ($policyContext !== null) {
            throw new InvalidArgumentException(sprintf(
                'Query policy context must be an array or %s; got %s.',
                PolicyContextInterface::class,
                get_debug_type($policyContext),
            ));
        }

        return [self::ATTR_QUERY => $query];
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<string, mixed>
     */
    private static function normalizeArrayContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Policy context keys must be strings.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
