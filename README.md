# Componenta CQRS Policy

Policy middleware package for `componenta/cqrs`.

Install it when command or query execution must be checked through `componenta/policy`.

```bash
composer require componenta/cqrs-policy
```

Register the provider:

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

Use `#[Allow]` from `componenta/policy` for public commands and queries. Place
policy before transport when an asynchronous command must be authorized before
enqueue. The transport worker does not set `ATTR_SKIP_POLICY` automatically.
Set that technical flag explicitly only when authorization already completed at
a trusted pre-enqueue boundary; middleware order alone is not an outbox.
