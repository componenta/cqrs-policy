<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware;

use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Resolver\ActionIdResolver;
use Componenta\CQRS\Resolver\ActionIdResolverInterface;
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\Guest;
use Componenta\Policy\Context\ContextInterface;
use Componenta\Policy\PolicyEnforcer;
use InvalidArgumentException;

/** Enforces command policies against the actor explicitly carried by the command. */
final readonly class PolicyMiddleware implements MiddlewareInterface
{
    /** Operation attribute key for the authorization context. */
    public const string ATTR_CONTEXT = '__policy_context';

    public const string ATTR_COMMAND = '__command';

    public const string ATTR_OPERATION = '__operation';

    /**
     * Operation attribute key to skip policy checks for a trusted technical flow.
     * Public commands should normally use an explicit allow policy instead.
     */
    public const string ATTR_SKIP_POLICY = '__skip_policy';

    public function __construct(
        private PolicyEnforcer $enforcer,
        private ActionIdResolverInterface $resolver = new ActionIdResolver(),
    ) {}

    public function execute(OperationInterface $operation, OperationHandlerInterface $handler): OperationInterface
    {
        if (($operation->attributes[self::ATTR_SKIP_POLICY] ?? false) === true) {
            return $handler->handle($operation);
        }

        $actionId = $this->resolver->resolve($operation->command);
        $actor = $operation->command instanceof ActorAwareInterface
            ? $operation->command->actor
            : new Guest();

        $this->enforcer->enforce(
            $actionId,
            $actor,
            $this->resolveContext($operation),
        );

        return $handler->handle($operation);
    }

    /** @return ContextInterface|array<string, mixed> */
    private function resolveContext(OperationInterface $operation): ContextInterface|array
    {
        $context = $operation->attributes[self::ATTR_CONTEXT] ?? [];

        if (is_array($context)) {
            $context = self::normalizeArrayContext($context);
            $context[self::ATTR_COMMAND] = $operation->command;
            $context[self::ATTR_OPERATION] = $operation;
        } elseif ($context instanceof ContextInterface) {
            $context = $context->withAttributes([
                self::ATTR_COMMAND => $operation->command,
                self::ATTR_OPERATION => $operation,
            ]);
        } else {
            throw new InvalidArgumentException(sprintf(
                'Policy context operation attribute must be an array or %s; got %s.',
                ContextInterface::class,
                get_debug_type($context),
            ));
        }

        return $context;
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
