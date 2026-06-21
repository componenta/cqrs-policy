<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware;

use Componenta\CQRS\Command\Exception\AuthenticationRequiredException;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\Policy\ActionIdAwareInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorProviderInterface;
use Componenta\Policy\Context\ContextInterface;
use Componenta\Policy\PolicyEnforcer;

/**
 * Middleware that enforces policy checks on commands.
 *
 * Uses PolicyEnforcer to check authorization based on policy attributes
 * defined on command classes. The action ID is the command's FQCN.
 *
 * Actor resolution priority:
 * 1. Operation attribute (ATTR_ACTOR) - allows per-request override
 * 2. ActorAwareInterface - actor embedded in the command itself
 * 3. ActorProviderInterface - global actor resolution (e.g., from session)
 *
 * @example Command with policy attribute
 * ```php
 * #[PermissionPolicy('posts.create')]
 * final readonly class CreatePostCommand
 * {
 *     public function __construct(
 *         public string $title,
 *         public string $content,
 *     ) {}
 * }
 * ```
 *
 * @example Command with composite policy
 * ```php
 * #[OneOf(
 *     new RolePolicy('admin'),
 *     new OwnerPolicy(),
 * )]
 * final readonly class UpdatePostCommand
 * {
 *     public function __construct(
 *         public int $postId,
 *         public string $title,
 *     ) {}
 * }
 * ```
 *
 * @example Passing actor via operation
 * ```php
 * $bus->dispatch($command, [
 *     PolicyMiddleware::ATTR_ACTOR => $user,
 * ]);
 * ```
 */
final readonly class PolicyMiddleware implements MiddlewareInterface
{
    /**
     * Operation attribute key for the actor.
     * Takes priority over ActorProviderInterface.
     */
    public const string ATTR_ACTOR = '__actor';

    /**
     * Operation attribute key for the authorization context.
     */
    public const string ATTR_CONTEXT = '__policy_context';

    public const string ATTR_COMMAND = '__command';

    public const string ATTR_OPERATION = '__operation';

    /**
     * Operation attribute key to skip policy check entirely.
     * Use for public commands (e.g., registration) that require no authorization.
     */
    public const string ATTR_SKIP_POLICY = '__skip_policy';

    /**
     * @param PolicyEnforcer $enforcer The policy enforcer
     * @param ActorProviderInterface|null $actorProvider Fallback actor resolution (used if ATTR_ACTOR not set)
     */
    public function __construct(
        private PolicyEnforcer $enforcer,
        private ?ActorProviderInterface $actorProvider = null,
        private ActionIdResolverInterface $resolver = new ActionIdResolver,
    ) {}

    public function execute(OperationInterface $operation, OperationHandlerInterface $handler): OperationInterface
    {
        if (($operation->attributes[self::ATTR_SKIP_POLICY] ?? false) === true) {
            return $handler->handle($operation);
        }

        $actionId = $this->resolver->resolve($operation->command);

        $actor = $this->resolveActor($operation, $actionId);

        $this->enforcer->enforce($actionId, $actor, $this->resolveContext($operation));

        return $handler->handle($operation);
    }

    /**
     * Resolves actor from operation attributes or provider.
     *
     * @throws AuthenticationRequiredException If no actor can be resolved
     */
    private function resolveActor(OperationInterface $operation, string $actionId): object
    {
        // Priority 1: Actor from operation attributes
        if (array_key_exists(self::ATTR_ACTOR, $operation->attributes)) {
            $actor = $operation->attributes[self::ATTR_ACTOR];

            if (!is_object($actor)) {
                throw new AuthenticationRequiredException(
                    $actionId,
                    sprintf(
                        "Invalid actor type in operation attributes: expected object, got '%s'",
                        get_debug_type($actor),
                    ),
                );
            }

            return $actor;
        }

        // Priority 2: Actor from command (ActorAwareInterface)
        if ($operation->command instanceof ActorAwareInterface) {
            return $operation->command->actor;
        }

        // Priority 3: Actor from provider
        if ($this->actorProvider !== null) {
            $actor = $this->actorProvider->getActor();

            if ($actor !== null) {
                return $actor;
            }
        }

        // No actor available
        throw new AuthenticationRequiredException(
            $actionId,
            $this->actorProvider !== null
                ? 'ActorProvider returned null - user may not be authenticated'
                : 'No actor provided in operation attributes and no ActorProvider configured',
        );
    }

    /**
     * @return ContextInterface|array<string, mixed>
     */
    private function resolveContext(OperationInterface $operation): ContextInterface|array
    {
        $context = $operation->attributes[self::ATTR_CONTEXT] ?? [];

        if (is_array($context)) {
            $context[self::ATTR_COMMAND] = $operation->command;
            $context[self::ATTR_OPERATION] = $operation;
        }

        elseif ($context instanceof ContextInterface) {
            $context = $context->withAttributes([
                self::ATTR_COMMAND => $operation->command,
                self::ATTR_OPERATION => $operation,
            ]);
        }

        return $context;
    }
}
