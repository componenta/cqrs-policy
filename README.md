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

A command has exactly one actor source:

```text
command implements ActorAwareInterface -> command actor object
command does not implement it           -> Guest
```

Command operation attributes and `ActorProviderInterface` are not command actor sources. This makes synchronous, nested, replayed, and transported command execution use the same explicit contract.

```php
use Componenta\Policy\Actor\ActorAwareInterface;

final readonly class PublishPostCommand implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
        public int $postId,
    ) {}
}
```

`ActorAwareInterface::$actor` is intentionally `object`, matching `PolicyEnforcer` and `PolicyInterface`. A command can therefore carry a user, system subject, `Guest`, or any custom policy actor. Concrete policies validate only the capabilities they require.

`Componenta\CQRS\Resolver\ActorResolver` extracts the object and returns `null` for an anonymous message. Command policy middleware creates a `Guest` only when the command is not actor-aware. Use an explicit allow policy for public commands; protected policies should deny actors that do not provide their required capabilities.

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

The standard actor-aware serializer supports exactly two policy actor reference forms:

```json
{"type":"guest"}
```

for `Componenta\Policy\Actor\Guest`, and:

```json
{"type":"identity","uuid":"00000000-0000-7000-8000-000000000001"}
```

for actors implementing `Componenta\Identity\IdentityInterface`.

An actor-aware command is written using the current versioned payload envelope:

```json
{
  "__componenta_cqrs": 2,
  "data": {
    "actor": {
      "type": "identity",
      "uuid": "00000000-0000-7000-8000-000000000001"
    },
    "postId": 42
  }
}
```

The serializer does not accept legacy UUID-only actor references or unversioned actor-aware payloads.

Serialization semantics:

- ordinary non-actor-aware commands are delegated to `JsonCommandSerializer`;
- `Guest` is restored as a fresh stateless `Guest` and does not use the actor repository;
- `IdentityInterface` actors are persisted by UUID and restored through `ActorRepositoryInterface`;
- a repository result for an identity reference must implement `IdentityInterface` and retain the requested UUID;
- the restored command must retain the exact actor instance produced by transport restoration;
- any other actor object is unsupported by the standard serializer.

Applications that use additional actor kinds such as a stateless system principal, API client, or service account may replace `CommandSerializerInterface` with an application-specific implementation. The base integration intentionally does not introduce a generic actor codec or registry abstraction for those application semantics.

Missing actors, malformed actor references, invalid UUIDs, repository identity mismatches, unknown fields, executable callables, and unsupported command shapes fail closed.

The command worker and envelope remain unchanged: the serializer returns a complete command whose actor is already restored before the existing command bus is called.

## Middleware placement

Place command policy before transport when enqueue itself must be authorized. The transport worker does not set `ATTR_SKIP_POLICY` automatically, so the restored actor-aware command is checked again during execution unless a trusted technical flow explicitly opts out.

`ATTR_SKIP_POLICY` is a technical escape hatch, not the public-access model. Middleware order alone is not an outbox or an authorization proof.
