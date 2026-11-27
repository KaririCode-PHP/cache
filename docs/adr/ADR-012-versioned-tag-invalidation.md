# ADR-012: Tag Invalidation with Random Versions

## Status

Accepted — V4.0.0 · Revised V4.1.0

## Date

2026-03-01 · revised 2026-09-30

## Context

Invalidating "everything about user 42" by deleting keys requires knowing
the keys. Tag versioning avoids that: each tag has a version, each entry
remembers the versions it was written under, and invalidation changes the
version.

4.0 used an integer counter per tag, read-modify-written with `+1`, and a
missing counter read as version 0. Reproduced problems:

- a user array shaped like the internal array envelope was returned
  "unwrapped";
- after `invalidateTags()`, losing the counter key (eviction, restart, flush
  of that key) turned the old entries valid again, because they had been
  written under version 0;
- two concurrent invalidations could both write `n + 1`, so one of them was
  lost.

### Prior Art

- **Symfony `TagAwareAdapter`** — tag versions stored next to the items;
  items are checked against the current versions on read.
- **Generational keys** — embed a namespace version in the key and bump it
  to invalidate a whole group.

## Decision

- Tag version key: `__tag_version:{tag}`; user keys starting with that
  prefix are rejected (`InvalidKeyException::reservedPrefix()`, 8632).
- A new version is `random_int(1, PHP_INT_MAX)`; `invalidateTags()` writes a
  fresh one. Concurrent invalidations therefore cannot cancel each other.
- When an entry is written, the versions of its tags are read — and created
  when missing — **before** the factory runs, so an invalidation during the
  computation wins.
- An entry is valid only while every recorded version equals the current
  one; a missing version means invalid.
- Every write stores a `TaggedEntry` (ADR-009).

## Consequences

### Positive

- `invalidateTags()` is O(number of tags), independent of the number of
  entries.
- Losing tag keys errs on the safe side (misses, never stale resurrections).

### Negative

- A read of an entry with *n* tags costs 1 + *n* backend reads.
- Tag version keys have no TTL and stay until flushed.
- Invalidation is lazy: stale entries occupy space until they expire.

## Alternatives Considered

1. **Counters with an atomic increment** — rejected: needs an atomic
   capability not present in `CacheAdapter`, and still resurrects entries
   when the counter is lost.
2. **Tag → keys index** — rejected: unbounded index growth and a
   read-modify-write per tagged write.

## References

- Symfony Cache, cache invalidation with tags —
  https://symfony.com/doc/current/components/cache/cache_invalidation.html
- ARFA 1.3 — P4 (Protocol Agnosticism)
