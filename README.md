# Componenta CQRS Policy

Policy middleware integration for `componenta/cqrs` and `componenta/policy`.

```bash
composer require componenta/cqrs-policy
```

Register the providers:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\Policy\ConfigProvider(),
    new Componenta\CQRS\Policy\ConfigProvider(),
];
```

The package keeps the middleware FQCNs stable:

- `Componenta\CQRS\Command\Middleware\PolicyMiddleware`
- `Componenta\CQRS\Query\Middleware\PolicyMiddleware`

## Command actors

A command has exactly one identity source:

```text
command implements ActorAwareInterface -> command actor
command does not implement it           -> Guest
```

Command operation attributes and `ActorProviderInterface` are not actor sources. This makes synchronous, nested, replayed, and transported command execution use the same explicit contract.

```php
use Componenta\Policy\Actor\ActorAwareInterface;
use Componenta\Policy\Actor\ActorInterface;

final readonly class PublishPostCommand implements ActorAwareInterface
{
    public function __construct(
        public ActorInterface $actor,
        public int $postId,
    ) {}
}
```

`Componenta\CQRS\Resolver\ActorResolver` extracts that actor and returns `null` for an anonymous message. Command policy middleware passes a `Guest` to the enforcer when the command is not actor-aware. Use an explicit allow policy for public commands; protected policies should deny `Guest`.

Query policy keeps its per-call query-context behavior because queries do not cross the command transport boundary.

## Asynchronous actor-aware commands

The default transport JSON serializer intentionally rejects arbitrary objects. To transport an actor-aware command, install the transport package and explicitly register the optional integration provider:

```bash
composer require componenta/cqrs-transport
```

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\Policy\ConfigProvider(),
    new Componenta\CQRS\Policy\ConfigProvider(),
    new Componenta\CQRS\Transport\ConfigProvider(),
    new Componenta\CQRS\Policy\Transport\ConfigProvider(),
];
```

Bind an application implementation of:

```php
Componenta\CQRS\Policy\Transport\ActorRepositoryInterface
```

The actor-aware serializer:

- delegates ordinary commands to `JsonCommandSerializer`;
- writes only the actor UUID for an `ActorAwareInterface` command;
- loads the current actor from `ActorRepositoryInterface` during deserialization;
- rejects missing actors, invalid UUIDs, repository identity mismatches, unknown fields, and unsupported command shapes.

The command worker and envelope remain unchanged: the serializer returns a complete command whose actor is already restored before the existing command bus is called.

## Middleware placement

Place command policy before transport when enqueue itself must be authorized. The transport worker does not set `ATTR_SKIP_POLICY` automatically, so the restored actor-aware command is checked again during execution unless a trusted technical flow explicitly opts out.

`ATTR_SKIP_POLICY` is a technical escape hatch, not the public-access model. Middleware order alone is not an outbox or an authorization proof.
