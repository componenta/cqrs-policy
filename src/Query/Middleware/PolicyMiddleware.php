<?php

declare(strict_types=1);

namespace Componenta\CQRS\Query\Middleware;

use Componenta\CQRS\Query\Context\ContextInterface;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;
use Componenta\Policy\Context\ContextInterface as PolicyContextInterface;
use Componenta\Policy\PolicyEnforcer;
use InvalidArgumentException;

/**
 * Enforces query policies against the actor explicitly carried by the query.
 *
 * Actor resolution is intentionally identical to the command-side model:
 * ActorAwareInterface queries use their explicit actor; every other query is
 * evaluated as Guest. CQRS policy does not resolve an ambient actor.
 *
 * Action IDs are resolved through {@see ActionIdResolverInterface}; the default
 * resolver uses {@see \Componenta\Policy\ActionIdAwareInterface} when present
 * and otherwise falls back to the query class name.
 *
 * Public queries normally omit ActorAwareInterface and use an explicit
 * `#[Allow]` policy. Protected queries that run for an authenticated subject
 * carry that subject explicitly through ActorAwareInterface.
 *
 * {@see self::ATTR_SKIP_POLICY} is reserved for trusted technical flows where
 * policy evaluation has already happened or cannot be performed in the current
 * process.
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
 * @example Public query evaluated as Guest
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

    /** Policy-level context override. Value must be an array or policy context. */
    public const string ATTR_POLICY_CONTEXT = '__policy_context';

    /** Policy-context key exposing the current query. */
    public const string ATTR_QUERY = '__query';

    public function __construct(
        private PolicyEnforcer $enforcer,
        private ActionIdResolverInterface $resolver = new ActionIdResolver(),
    ) {}

    public function handle(object $query, ContextInterface $context, callable $next): mixed
    {
        if ($context->getAttribute(self::ATTR_SKIP_POLICY) === true) {
            return $next($query, $context);
        }

        $actionId = $this->resolver->resolve($query);
        $actor = $query instanceof ActorAwareInterface
            ? $query->actor
            : new Guest();

        $this->enforcer->enforce(
            $actionId,
            $actor,
            $this->resolvePolicyContext($query, $context),
        );

        return $next($query, $context);
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
