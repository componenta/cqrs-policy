# Componenta CQRS Policy

Policy middleware integration for `componenta/cqrs` and `componenta/policy`.

```bash
composer require componenta/cqrs-policy
```

Register the core providers:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\Policy\ConfigProvider(),
    new Componenta\CQRS\Policy\ConfigProvider(),
];
```

The policy provider registers middleware **services**, but does not insert them into the CQRS pipelines because middleware composition and ordering belong to the application. Add the command and/or query middleware explicitly where policy enforcement is required:

```php
use Componenta\CQRS\ConfigKey;

return [
    ConfigKey::COMMAND_MIDDLEWARES => [
        Componenta\CQRS\Command\Middleware\PolicyMiddleware::class,
    ],
    ConfigKey::QUERY_MIDDLEWARES => [
        Componenta\CQRS\Query\Middleware\PolicyMiddleware::class,
    ],
];
```

The core package exposes:

- `Componenta\CQRS\Command\Middleware\PolicyMiddleware`
- `Componenta\CQRS\Query\Middleware\PolicyMiddleware`

The core policy integration does not require `componenta/cqrs-transport`. It may coexist with an older transport version when actor-aware transport integration is not registered.

## CQRS actors

Commands and queries use one actor model:

```text
message implements ActorAwareInterface -> message actor object
message does not implement it           -> Guest
```

CQRS policy resolves the actor only from the message itself. When the message does not explicitly carry an actor, policy evaluation uses `Guest`. This keeps synchronous, nested, replayed, CLI, and transported execution on the same explicit contract.

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

```php
use Componenta\Policy\Actor\ActorAwareInterface;

final readonly class GetMyOrders implements ActorAwareInterface
{
    public function __construct(
        public object $actor,
    ) {}
}
```

`ActorAwareInterface::$actor` is intentionally `object`, matching `PolicyEnforcer` and `PolicyInterface`. There is no universal composite actor interface. Domain subjects implement only the capabilities their policies require.

`Guest` is the built-in anonymous policy actor. A public query can simply omit `ActorAwareInterface` and use an explicit `#[Allow]` policy. A protected query without an explicit actor is evaluated as Guest and is denied normally by permission/role/owner policies.

`ATTR_SKIP_POLICY` remains only a trusted technical escape hatch. It is not an authentication mechanism.

## Asynchronous actor-aware commands

Actor-aware transport integration requires `componenta/cqrs-transport` 4.0+ and is enabled explicitly:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\Policy\ConfigProvider(),
    new Componenta\CQRS\Policy\ConfigProvider(),
    new Componenta\CQRS\Transport\ConfigProvider(),
    new Componenta\CQRS\Policy\Transport\ConfigProvider(),
];
```

The version requirement belongs to this optional provider, not the core package. Registering the transport integration with a transport version that lacks the composite serializer API fails immediately with a clear configuration error.

Bind an application implementation of:

```php
Componenta\CQRS\Policy\Transport\ActorRepositoryInterface
```

The integration installs an ordered `CompositeCommandSerializer`:

```text
ActorAwareJsonCommandSerializer
JsonCommandSerializer
```

The actor-aware serializer implements both `CommandSerializerInterface` and `CommandSerializerSupportInterface` and owns only `ActorAwareInterface` command types. It contains no fallback logic of its own. Ordinary commands reach the broad JSON serializer through the composite.

Composite support must be stable for the command class: a serializer must make the same support decision for an instance and for that instance's class name, because deserialization has no command instance yet. The composite verifies this invariant when serializing. Support predicates must therefore be deterministic, side-effect free, and independent of actor value or other per-instance state.

If the same command class can carry a standard actor in one instance and an application-specific actor in another, a custom serializer cannot claim only the latter instance. It must own that entire command class and understand every wire variant it accepts, or the application should use distinct command types.

Applications that need another command or actor wire format register their own serializer ahead of the actor-aware serializer in an application-owned composite. A serializer failure is final; selection never falls through after malformed payload, missing actor, or another validation error.

### Standard actor references

The standard actor-aware serializer supports two actor forms:

```json
{"type":"guest"}
```

for `Componenta\Policy\Actor\Guest`, and:

```json
{"type":"identity","uuid":"00000000-0000-7000-8000-000000000001"}
```

for actors implementing `Componenta\Identity\IdentityInterface`.

An actor-aware command uses one current versioned wire contract:

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

UUID-only actor references and unversioned actor-aware payloads are not accepted.

Serialization semantics:

- `Guest` is restored as a fresh stateless Guest without repository access;
- an `IdentityInterface` actor is stored by UUID and restored through `ActorRepositoryInterface`;
- an identity repository result must implement `IdentityInterface` and retain the requested UUID;
- the restored command must retain the exact actor instance produced by restoration;
- constructor reconstruction must not change other serialized command state;
- JSON integer and float are distinct wire types; floats preserve fractional form and signed zero, a JSON integer is not accepted for a `float` constructor field, and integer tokens outside the PHP integer range are rejected instead of being coerced to float;
- after encoding, the serializer decodes its own envelope and rejects any state changed by PHP JSON precision settings;
- recursive/excessively deep arrays, unknown fields, executable callables, hooked/virtual properties, private state including inherited private state, dynamic properties, and unsupported actor kinds fail closed;
- dynamic runtime state is rejected both before serialization and after command reconstruction instead of being silently dropped;
- non-actor payload values and nesting are validated before command construction.

The actor UUID is a persistence reference, not an authentication credential. The standard integration assumes queued payloads originate from trusted producers and are protected against unauthorized modification. If that assumption does not hold, integrity protection must cover the complete envelope/payload rather than only the actor reference.

The command worker and envelope remain generic and policy-agnostic: deserialization returns a complete command before the existing command bus and policy middleware run.

## Middleware placement

Command middleware order is application configuration; `cqrs-policy` does not force its position relative to event, transport, lock, retry, transaction, or custom middleware.

Placing command policy outside infrastructure side effects is a common choice when those effects should occur only for authorized commands. For example:

```text
PolicyMiddleware
  TransportMiddleware
    handler
```

authorizes before enqueue, while:

```text
TransportMiddleware
  PolicyMiddleware
    handler
```

queues first and performs policy evaluation only when the command later reaches synchronous execution, such as worker redispatch. Both are valid pipeline topologies; the application chooses which boundary is authoritative.

The same principle applies to events, locking, retry, and transactions. Their placement changes behavior and must be selected deliberately by application configuration.

`ATTR_SKIP_POLICY` remains a trusted application-controlled dispatch flag and is never transported by the standard operation-context serializer.
