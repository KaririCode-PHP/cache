# ADR-006: Namespace Scoping

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

Multi-tenant applications and modules that share one backend need key
isolation without passing a prefix around by hand.

### Prior Art

- **Symfony Cache** — each pool has a namespace; keys of different pools do
  not collide.
- **Laravel** — a store-wide key `prefix` in the cache configuration.

## Decision

`Cache::withScope(string $scope)` returns a `ScopedCache` decorator that
rewrites every key as `"{$scope}:{$key}"`. Scopes nest:

```php
$users = $cache->withScope('tenant:42')->withScope('users');
$users->set('7', $profile);            // stored as "tenant:42:users:7"
```

The colon is therefore allowed in native keys (ADR-011). Every `Cache`
implementation returns a `ScopedCache`, so scoping composes with
`TagAwareCache` and `LayeredCache`.

### Revision 4.1.0

1. **Scope validation.** An empty scope, or one containing `{}()/\@`,
   raises `ConfigurationException` (8640) at construction; 4.0
   produced keys such as `":key"`.
2. **`final readonly`.** The decorator holds no mutable state.
3. **`clear()` documented.** It delegates to the inner cache and clears
   every scope that shares it.

## Consequences

### Positive

- One line isolates a tenant; nested scopes model tenant → module → entity.
- No backend-specific feature needed.

### Negative

- `clear()` is not scoped. To drop one tenant, give it its own adapter
  prefix (`RedisAdapter(prefix: 'tenant42:')`, whose `flush()` deletes only
  that prefix) or tag its entries (ADR-012).
- The scope becomes part of every stored key: renaming a scope orphans its
  entries until they expire.

## Alternatives Considered

1. **Prefix in the adapter only** — kept for per-tenant `flush()`, but it
   needs one adapter instance per tenant.
2. **Scoped `clear()` through key enumeration** — rejected: needs SCAN-like
   enumeration, which APCu and the in-memory adapter cannot do efficiently.

## References

- Symfony Cache, cache pools and namespaces — https://symfony.com/doc/current/cache.html
- Laravel cache configuration — https://laravel.com/docs/cache#configuration
- ARFA 1.3 — P4 (Protocol Agnosticism)
