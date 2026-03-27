# Benchmark Notes (Hot JSON Endpoints)

## Scope
Initial benchmark guidance for low-latency JSON endpoints in Foundry v1.

## Key design choices affecting performance
- precompiled route/feature indexes loaded from `app/generated/*.php`
- no feature-folder scanning on request hot path
- explicit arrays/final classes over deep container abstraction
- explicit SQL named queries (no runtime ORM reflection)
- schema validator caches parsed schema documents in-process

## Suggested benchmark setup
- PHP-FPM or persistent worker mode
- warm opcode cache before measuring
- test representative endpoint mix:
  - small read (`GET /posts`)
  - authenticated write (`POST /posts`)
  - event + job dispatch side effects
- collect p50/p95/p99 latency and requests/sec

## Current status
- Runtime and CLI pipelines are implemented and covered by tests.
- No full synthetic throughput run is included yet in-repo.

## Next benchmark step
Add a repeatable script that:
1. primes generated indexes and schemas
2. sends steady-state request load to `public/index.php`
3. records latency distribution and CPU/memory profile
