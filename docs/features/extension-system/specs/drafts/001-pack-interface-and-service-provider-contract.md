# Execution Spec: 001-pack-interface-and-service-provider-contract

## Purpose

Define the canonical PHP contract for Foundry packs, enabling deterministic, side-effect-free extension registration through a strict ServiceProvider interface.

This spec ensures packs are implementable by Codex, composable, and safe to load within the extension system.

---

## Feature

`extension-system`

---

## Goals

1. Define a single, canonical pack entry contract.
2. Ensure deterministic registration of pack capabilities.
3. Prevent global state and side effects during registration.
4. Enable safe extension of commands, schemas, workflows, and generators.
5. Provide a stable foundation for ecosystem growth.

---

## Non-Goals

- Do not define pack distribution or installation (covered in Spec 011).
- Do not introduce runtime dependency injection containers.
- Do not allow dynamic or conditional registration.
- Do not permit side effects during pack loading.
- Do not introduce lifecycle hooks beyond registration.

---

## Pack Structure

A valid pack MUST follow this structure:

```text
/pack-root
  /src
    PackServiceProvider.php
  foundry.json
```

Rules:

- `foundry.json` MUST exist at the root
- `src/` MUST contain the entry class
- No implicit file discovery allowed in V1

---

## foundry.json Contract

Each pack MUST define a valid `foundry.json`.

Canonical shape:

```json
{
  "name": "vendor/pack",
  "version": "1.0.0",
  "entry": "Vendor\\Pack\\PackServiceProvider"
}
```

Rules:

- `name` MUST be unique
- `version` MUST be explicit (no ranges)
- `entry` MUST be a fully-qualified class name
- No additional required fields in V1

---

## Service Provider Contract

Packs MUST implement the following interface:

```php
interface PackServiceProvider
{
    public function register(PackContext $context): void;
}
```

Rules:

- `register()` MUST be the only required method
- MUST NOT perform side effects outside registration
- MUST NOT depend on runtime environment conditions
- MUST NOT modify global state

---

## PackContext Contract

The `PackContext` provides controlled registration methods.

Canonical interface:

```php
class PackContext
{
    public function registerCommand(...);
    public function registerSchema(...);
    public function registerWorkflow(...);
    public function registerGenerator(Generator $generator);
}
```

Rules:

- All registration MUST go through `PackContext`
- No direct mutation of framework internals
- Methods MUST be deterministic
- Arguments MUST be explicit and serializable where applicable

---

## Registration Model

### Execution Flow

1. Load installed packs from extension manifest
2. Resolve `foundry.json`
3. Instantiate `PackServiceProvider`
4. Invoke `register(PackContext $context)`
5. Collect all registrations
6. Apply registrations deterministically

---

## Determinism Rules

- Registration order MUST be stable
- No conditional branching based on runtime state
- No randomness
- No environment-dependent behavior
- Same pack version MUST produce identical registrations

---

## Side-Effect Rules

During `register()`:

Allowed:
- calling `PackContext` methods

Forbidden:
- file system writes
- network calls
- environment inspection
- logging
- executing commands
- mutating global/static state

---

## Error Handling

Registration MUST fail when:

- entry class does not exist
- class does not implement `PackServiceProvider`
- `register()` throws
- invalid arguments passed to `PackContext`

Failures MUST:

- stop pack loading
- report deterministic error messages

---

## Inspect Surface Requirements

Inspect MUST expose:

- loaded packs
- versions
- registered commands
- registered workflows
- registered generators

Output MUST be deterministic.

---

## Verify Surface Requirements

Verify MUST fail when:

- pack structure is invalid
- `foundry.json` is malformed
- entry class is missing
- provider does not implement interface
- registration is non-deterministic
- duplicate registrations occur

---

## Compatibility Requirements

- Existing extension loading MUST remain functional
- This contract MUST be additive
- No breaking changes to existing packs unless explicitly migrated

---

## Tests Required

1. Valid pack loads successfully
2. Invalid structure fails
3. Missing entry class fails
4. Interface enforcement
5. Deterministic registration order
6. PackContext registration correctness
7. Verify failure cases
8. No side-effect enforcement

---

## Acceptance Criteria

- Packs implement a single ServiceProvider contract
- Registration is deterministic and side-effect-free
- PackContext is the only registration surface
- Invalid packs are rejected
- Inspect and verify surfaces support packs
- All tests pass
- Strict coverage gate exits 0

---

## Done Means

Foundry packs have a strict, deterministic, and enforceable contract, enabling safe ecosystem expansion and consistent Codex-driven implementation.
