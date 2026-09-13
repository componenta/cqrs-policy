<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Query\QueryBusInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Policy\Attribute\Policy;
use Componenta\Policy\Context\ContextInterface;
use Componenta\Policy\Exception\AccessDeniedException;
use Componenta\Policy\Exception\DenyReason;
use Componenta\Policy\PolicyInterface;
use Psr\Container\ContainerInterface;

final class IntegratedPolicyState
{
    public int $handlerCalls = 0;
    public int $policyCalls = 0;
}
final readonly class IntegratedPolicy implements PolicyInterface
{
    public function __construct(private IntegratedPolicyState $state, private bool $allowed = true)
    {
    }
    public function enforce(object $actor, ContextInterface $context): true|DenyReason
    {
        ++$this->state->policyCalls;
        return $this->allowed ? true : new DenyReason('integration denied');
    }
}
final class ConfiguredPolicyMessage
{
}
#[Policy(IntegratedPolicy::class, ['allowed' => false])]
final class AttributedPolicyMessage
{
}
final readonly class IntegratedPolicyHandler
{
    public function __construct(private IntegratedPolicyState $state)
    {
    }
    public function __invoke(object $message): string
    {
        ++$this->state->handlerCalls;
        return 'handled';
    }
}

it('enforces configured and attribute policies through the registered command and query buses', function (): void {
    $registrations = array_map(static fn (string $message): array => [
        'message' => $message, 'service' => IntegratedPolicyHandler::class, 'method' => '__invoke',
    ], [ConfiguredPolicyMessage::class, AttributedPolicyMessage::class]);
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new \Componenta\CQRS\ConfigProvider(),
        new \Componenta\Policy\ConfigProvider(),
        new \Componenta\CQRS\Policy\ConfigProvider(),
        static fn (): array => [
            ConfigKey::COMMAND_HANDLERS => $registrations,
            ConfigKey::QUERY_HANDLERS => $registrations,
            ConfigKey::COMMAND_MIDDLEWARES => [\Componenta\CQRS\Command\Middleware\PolicyMiddleware::class],
            ConfigKey::QUERY_MIDDLEWARES => [\Componenta\CQRS\Query\Middleware\PolicyMiddleware::class],
            \Componenta\Policy\ConfigKey::POLICY => [
                \Componenta\Policy\ConfigKey::POLICIES => [
                    ConfiguredPolicyMessage::class => static fn (ContainerInterface $container): PolicyInterface => $container->get(IntegratedPolicy::class),
                ],
            ],
        ],
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $commands = $container->get(CommandBusInterface::class);
    $queries = $container->get(QueryBusInterface::class);

    expect($commands->dispatch(new ConfiguredPolicyMessage())->result?->value)->toBe('handled')
        ->and($queries->handle(new ConfiguredPolicyMessage()))->toBe('handled');
    expect(fn () => $commands->dispatch(new AttributedPolicyMessage()))->toThrow(AccessDeniedException::class, 'integration denied');
    expect(fn () => $queries->handle(new AttributedPolicyMessage()))->toThrow(AccessDeniedException::class, 'integration denied');
    expect($container->get(IntegratedPolicyState::class)->handlerCalls)->toBe(2)
        ->and($container->get(IntegratedPolicyState::class)->policyCalls)->toBe(4);
});
