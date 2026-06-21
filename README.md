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

Use `#[Allow]` from `componenta/policy` for public commands and queries. `ATTR_SKIP_POLICY` is a technical flag for cases where authorization already happened earlier, for example worker re-dispatch.