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

The core package exposes:

- `Componenta\CQRS\Command\Middleware\PolicyMiddleware`
- `Componenta\CQRS\Query\Middleware\PolicyMiddleware`

The core policy integration does not require `componenta/cqrs-transport`. It may coexist with an older transport version when actor-aware transport integration is not registered.

## Command actors

A command has exactly one actor source:

```text
command implements ActorAwareInterface -> command actor object
command does not implement it           -> Guest
```

Command operation attributes and `ActorProviderInterface` are not command actor sources. This keeps synchronous, nested, replayed, and transported command execution on the same explicit contract.

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

`ActorAwareInterface::$actor` is intentionally `object`, matching `PolicyEnforcer` and `PolicyInterface`. There is no universal composite actor interface. Domain subjects implement only the capabilities their policies require.

`Guest` is a first-class anonymous policy actor. Built-in protected policies deny Guest normally; unrelated objects that do not implement the capability expected by a policy remain policy integration errors.

## Query actors

Queries retain a per-call actor resolution chain:

```text
query context ATTR_ACTOR
-> ActorAwareInterface query
-> ActorProviderInterface
```

`ActorProviderInterface` may return a concrete actor, `Guest` when that provider explicitly represents anonymous access, or `null` when no actor can be resolved. Query middleware does not convert `null` to Guest; unresolved actors produce `Query\Exception\AuthenticationRequiredException`.

Public queries should use an explicit `#[Allow]` policy together with an actor source that intentionally supplies Guest. `ATTR_SKIP_POLICY` is reserved for trusted technical flows, not the ordinary public-access model.

## Asynchronous actor-aware commands

Actor-aware transport integration requires a composite-capable `componenta/cqrs-transport` 3.1+ and is enabled explicitly:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\Policy\ConfigProvider(),
    new Componenta\CQRS\Policy\ConfigProvider(),
    new Componenta\CQRS\Transport\ConfigProvider(),
    new Componenta\CQRS\Policy\Transport\ConfigProvider(),
];
```

The version requirement belongs to this optional provider, not the core package. Registering the transport integration with a transport version that lacks `CommandSerializerSupportInterface` / `CompositeCommandSerializer` fails immediately with a clear configuration error.

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

Composite support must be stable for the command class: a serializer must make the same support decision for an instance and for that instance's class name, because deserialization has no command instance yet. Support predicates therefore must not depend on actor value or other per-instance state.

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
- recursive/excessively deep arrays, unknown fields, executable callables, hooked/virtual properties, private state, and unsupported actor kinds fail closed;
- non-actor payload values and nesting are validated before command construction.

The actor UUID is a persistence reference, not an authentication credential. The standard integration assumes queued payloads originate from trusted producers and are protected against unauthorized modification. If that assumption does not hold, integrity protection must cover the complete envelope/payload rather than only the actor reference.

The command worker and envelope remain generic and policy-agnostic: deserialization returns a complete command before the existing command bus and policy middleware run.

## Middleware placement

Place command policy before transport when enqueueing itself must be authorized. The transport worker does not contain or set a policy-specific skip constant. A trusted technical flow may still supply `PolicyMiddleware::ATTR_SKIP_POLICY` explicitly as application dispatch configuration, but ordinary worker execution re-evaluates the restored command actor.

Middleware order is an application configuration contract; it is not an outbox or an authorization proof.
