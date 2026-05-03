# 001-feature-boundaries-and-localized-runtime-layout

## Purpose

Introduce a mandatory, modular feature layout that localizes each feature's documentation, specifications, implementation code, tests, plans, and supporting docs into a single feature-owned directory.

This reduces cross-repository context requirements for humans and LLM agents, improves feature portability, and creates enforceable boundaries that prevent feature-specific logic from spreading into shared framework files.

## Core Principle

A feature owns its own runtime, tests, specifications, plans, and documentation.

Shared framework surfaces may provide routing, discovery, and registration glue, but feature behavior must live inside the owning feature directory.

## Target Layout

Introduce the canonical top-level feature workspace:

```text
Features/
  implementation.log
  README.md

  <FeaturePascalName>/
    <feature-kebab-name>.md
    <feature-kebab-name>.decisions.md
    <feature-kebab-name>.spec.md

    specs/
    plans/
    docs/
    src/
    tests/
```

Examples:

```text
Features/EventSystem/event-system.md
Features/EventSystem/event-system.decisions.md
Features/EventSystem/event-system.spec.md
Features/EventSystem/specs/001-registry-synchronous-deterministic-dispatch.md
Features/EventSystem/plans/001-registry-synchronous-deterministic-dispatch.md
Features/EventSystem/src/EventRegistry.php
Features/EventSystem/tests/EventRegistryTest.php

Features/ExtensionSystem/extension-system.md
Features/McpServer/mcp-server.spec.md
```

## Goals

1. Establish `Features/` as the canonical feature workspace.
2. Co-locate each feature's docs, specs, plans, source code, and tests.
3. Preserve deterministic execution-spec validation.
4. Preserve context anchoring and decision-ledger rules.
5. Add mandatory feature-boundary enforcement by default.
6. Allow explicit opt-out only through a visible configuration flag.
7. Keep shared framework files thin and free of feature-specific behavior.
8. Add deterministic CLI surfaces for feature inspection, mapping, and boundary verification.
9. Provide compatibility handling for the existing `docs/features/` layout during migration.
10. Keep all output stable, sorted, and suitable for LLM agents.

## Non-Goals

- Do not rewrite the entire framework into the new layout in one step unless required by tests.
- Do not remove existing runtime namespaces unless safely migrated.
- Do not introduce dynamic runtime discovery that depends on filesystem order.
- Do not hide feature boundary violations.
- Do not make boundary enforcement opt-in.
- Do not move canonical specs or decisions into SQLite.
- Do not introduce an event bus in this spec.

## Definitions

### Feature Workspace

The top-level `Features/` directory containing all feature-local modules and global feature metadata.

### Feature Directory

A single directory under `Features/` representing one feature.

Directory name format:

```text
<FeaturePascalName>
```

Examples:

```text
EventSystem
ExtensionSystem
McpServer
GenerateEngine
```

### Feature Slug

The kebab-case machine-readable feature identifier.

Examples:

```text
event-system
extension-system
mcp-server
generate-engine
```

### Feature-Owned Code

Code that implements feature-specific behavior.

Feature-owned code must live under:

```text
Features/<FeaturePascalName>/src/
```

### Feature-Owned Tests

Tests that primarily validate feature-specific behavior.

Feature-owned tests must live under:

```text
Features/<FeaturePascalName>/tests/
```

### Shared Framework Glue

Small framework-level code that routes to or registers feature-owned code without embedding feature-specific business logic.

Examples:

```text
src/CLI/Application.php
src/Support/ApiSurfaceRegistry.php
src/MCP/ToolRegistry.php
```

Shared framework glue is allowed, but it must remain thin and inspectable.

## Canonical Paths

### Global Feature Files

The following files are canonical:

```text
Features/README.md
Features/implementation.log
```

`Features/implementation.log` replaces the previous implementation-ledger location.

If an existing ledger exists at:

```text
docs/features/implementation-log.md
```

the implementation must either:

1. migrate it to `Features/implementation.log`, or
2. maintain a compatibility bridge during migration.

The canonical path after this spec is:

```text
Features/implementation.log
```

### Per-Feature Context Files

Each feature must have:

```text
Features/<FeaturePascalName>/<feature-slug>.spec.md
Features/<FeaturePascalName>/<feature-slug>.md
Features/<FeaturePascalName>/<feature-slug>.decisions.md
```

These preserve the existing intent/state/history model:

- `.spec.md` is the living feature contract.
- `.md` is current feature state.
- `.decisions.md` is append-only decision history.

### Execution Specs

Execution specs must live under:

```text
Features/<FeaturePascalName>/specs/
```

Draft execution specs must live under:

```text
Features/<FeaturePascalName>/specs/drafts/
```

Spec filenames remain unchanged:

```text
NNN-slug.md
NNN.NNN-slug.md
NNN.NNN.NNN-slug.md
```

### Plans

Implementation plans must live under:

```text
Features/<FeaturePascalName>/plans/
```

### Feature Docs

Feature-local supporting docs must live under:

```text
Features/<FeaturePascalName>/docs/
```

### Source

Feature-owned source code must live under:

```text
Features/<FeaturePascalName>/src/
```

### Tests

Feature-owned tests must live under:

```text
Features/<FeaturePascalName>/tests/
```

## Boundary Rules

### Rule 1: Feature Logic Must Stay Local

Feature-specific runtime behavior must live under:

```text
Features/<FeaturePascalName>/src/
```

Shared framework files may call feature-owned classes, but must not implement feature behavior directly.

Allowed shared glue:

- command registration
- provider registration
- registry wiring
- route/tool lookup
- stable API/help metadata references
- compatibility shims

Forbidden shared logic:

- feature-specific branching
- feature-specific validation rules
- feature-specific CLI execution behavior
- feature-specific MCP tool behavior
- feature-specific compiler behavior
- feature-specific generate behavior
- feature-specific doctor/verify behavior

### Rule 2: Feature Tests Must Stay Local

Feature-specific tests must live under:

```text
Features/<FeaturePascalName>/tests/
```

Shared framework tests may exist under global test directories only when validating framework-level behavior.

### Rule 3: Cross-Feature Dependencies Must Be Explicit

A feature may depend on another feature only when declared in a feature manifest or equivalent deterministic metadata source.

Add a per-feature manifest if one does not already exist:

```text
Features/<FeaturePascalName>/feature.json
```

Minimum manifest shape:

```json
{
  "slug": "event-system",
  "name": "EventSystem",
  "dependencies": [],
  "boundary": {
    "enforced": true
  }
}
```

Dependency order must be stable and deterministic.

### Rule 4: Shared Files Must Remain Thin

Shared framework files may contain feature references only as glue.

A shared file is suspect if it contains:

- large feature-specific conditionals
- feature-specific payload construction
- feature-specific validation logic
- feature-specific rendering logic
- feature-specific error handling
- direct knowledge of feature internals beyond public provider/contract classes

### Rule 5: Boundary Enforcement Is Mandatory By Default

Boundary enforcement must be enabled by default for framework and generated apps.

Projects may explicitly opt out, but opt-out must be visible in diagnostics.

Suggested config:

```json
{
  "features": {
    "enforce_boundaries": true,
    "allow_boundary_opt_out": true
  }
}
```

If enforcement is disabled:

- `doctor` must emit a warning.
- `verify features --json` must include `"enforcement": "disabled"`.
- generated app docs must state that disabling boundary enforcement is not recommended.

## CLI Requirements

Add deterministic CLI surfaces.

### `foundry feature:list`

Lists all known features.

Required JSON shape:

```json
{
  "features": [
    {
      "slug": "event-system",
      "name": "EventSystem",
      "path": "Features/EventSystem",
      "has_context": true,
      "has_specs": true,
      "has_src": true,
      "has_tests": true,
      "boundary_enforced": true
    }
  ]
}
```

Sorting:

- ascending by `slug`

### `foundry feature:inspect <feature>`

Shows one feature's localized structure.

Required JSON shape:

```json
{
  "feature": {
    "slug": "event-system",
    "name": "EventSystem",
    "path": "Features/EventSystem",
    "context": {
      "spec": "Features/EventSystem/event-system.spec.md",
      "state": "Features/EventSystem/event-system.md",
      "decisions": "Features/EventSystem/event-system.decisions.md"
    },
    "directories": {
      "specs": "Features/EventSystem/specs",
      "plans": "Features/EventSystem/plans",
      "docs": "Features/EventSystem/docs",
      "src": "Features/EventSystem/src",
      "tests": "Features/EventSystem/tests"
    },
    "dependencies": []
  }
}
```

### `foundry feature:map`

Produces a deterministic feature ownership map.

Required JSON shape:

```json
{
  "features": [
    {
      "slug": "event-system",
      "owned_paths": [
        "Features/EventSystem/event-system.md",
        "Features/EventSystem/src/EventRegistry.php",
        "Features/EventSystem/tests/EventRegistryTest.php"
      ],
      "shared_glue_paths": []
    }
  ],
  "unowned_paths": []
}
```

Sorting:

- features by slug
- paths lexicographically

### `foundry verify features`

Runs mandatory feature-boundary checks.

Required JSON shape on success:

```json
{
  "status": "ok",
  "enforcement": "enabled",
  "violations": [],
  "warnings": []
}
```

Required JSON shape on violation:

```json
{
  "status": "failed",
  "enforcement": "enabled",
  "violations": [
    {
      "code": "FEATURE_BOUNDARY_VIOLATION",
      "feature": "event-system",
      "path": "src/Support/ApiSurfaceRegistry.php",
      "message": "Feature-specific behavior must live under Features/EventSystem/src."
    }
  ],
  "warnings": []
}
```

If enforcement is disabled:

```json
{
  "status": "ok",
  "enforcement": "disabled",
  "violations": [],
  "warnings": [
    {
      "code": "FEATURE_BOUNDARY_ENFORCEMENT_DISABLED",
      "message": "Feature boundary enforcement is disabled. This is not recommended."
    }
  ]
}
```

## Validation Requirements

Update existing validation flows so they understand the new layout.

### `spec:validate`

Must validate specs under:

```text
Features/<FeaturePascalName>/specs/
```

and draft specs under:

```text
Features/<FeaturePascalName>/specs/drafts/
```

The existing filename, heading, duplicate-id, and forbidden-metadata rules still apply.

### `context check-alignment`

Must support:

```bash
php bin/foundry context check-alignment --feature=event-system --json
```

using the new paths.

### `verify context`

Must validate all feature context files under `Features/`.

### Legacy Path Compatibility

During migration, existing paths under `docs/features/` may be supported as read-only compatibility inputs.

However:

- new files must be created under `Features/`
- validation output must identify canonical `Features/` paths
- legacy paths must not be preferred over canonical paths
- duplicate feature definitions across legacy and canonical paths must produce deterministic diagnostics

Error code:

```text
FEATURE_DUPLICATE_CANONICAL_AND_LEGACY
```

## Runtime Registration Pattern

Introduce feature-level providers where useful.

Suggested interface:

```php
interface FeatureProvider
{
    public function register(FeatureContext $context): void;
}
```

Feature providers may register:

- CLI commands
- MCP tools
- doctor checks
- verify checks
- API/help metadata
- compiler hooks
- generate hooks
- pack hooks

Feature providers must be deterministic and side-effect constrained.

Feature-owned providers must live under:

```text
Features/<FeaturePascalName>/src/
```

Shared framework bootstrap may load providers in stable feature order.

## Migration Requirements

This spec must include a safe migration path from the current layout.

### Minimum Required Migration

At minimum, migrate or bridge the following:

```text
docs/features/implementation-log.md
docs/features/<feature>/<feature>.md
docs/features/<feature>/<feature>.decisions.md
docs/features/<feature>/<feature>.spec.md
docs/features/<feature>/specs/
docs/features/<feature>/plans/
```

to:

```text
Features/implementation.log
Features/<FeaturePascalName>/<feature>.md
Features/<FeaturePascalName>/<feature>.decisions.md
Features/<FeaturePascalName>/<feature>.spec.md
Features/<FeaturePascalName>/specs/
Features/<FeaturePascalName>/plans/
```

Do not delete legacy files unless the test suite and repository conventions are updated to the canonical layout.

If compatibility stubs or redirects are needed, they must be deterministic.

### Source/Test Migration

Do not attempt a full source/test migration unless it can be completed safely.

Instead, implement boundary mapping so existing global source/test paths can be classified as:

- owned feature code
- shared framework glue
- unowned code
- legacy code requiring future migration

This allows enforcement to begin without requiring the entire codebase to be physically moved in one spec.

## Error Codes

Add deterministic error codes:

```text
FEATURE_BOUNDARY_VIOLATION
FEATURE_UNKNOWN
FEATURE_INVALID_LAYOUT
FEATURE_MISSING_CONTEXT
FEATURE_MISSING_MANIFEST
FEATURE_DUPLICATE_CANONICAL_AND_LEGACY
FEATURE_BOUNDARY_ENFORCEMENT_DISABLED
FEATURE_SHARED_GLUE_TOO_SPECIFIC
FEATURE_DEPENDENCY_UNDECLARED
```

## Testing Requirements

### Unit Tests

Cover:

- feature slug to PascalCase directory mapping
- canonical path resolution
- manifest loading
- dependency sorting
- legacy path detection
- duplicate canonical/legacy detection
- boundary violation diagnostics
- disabled-enforcement warning

### Integration Tests

Cover:

- `feature:list --json`
- `feature:inspect <feature> --json`
- `feature:map --json`
- `verify features --json`
- `spec:validate --json` with canonical `Features/` specs
- `context check-alignment --feature=<feature> --json`
- `verify context --json`

### Migration Tests

Cover:

- existing `docs/features/` layout remains readable during migration
- canonical `Features/` layout is preferred
- duplicate legacy/canonical features fail deterministically
- implementation ledger canonical path is `Features/implementation.log`

### Determinism Tests

Run the same commands multiple times and assert identical JSON output:

```bash
php bin/foundry feature:list --json
php bin/foundry feature:map --json
php bin/foundry verify features --json
```

## Acceptance Criteria

- `Features/` is recognized as the canonical feature workspace.
- `Features/implementation.log` is recognized as the canonical implementation ledger.
- Feature context files are recognized under `Features/<FeaturePascalName>/`.
- Execution specs and drafts validate under `Features/<FeaturePascalName>/specs/`.
- Feature plans are stored under `Features/<FeaturePascalName>/plans/`.
- Feature source and tests have canonical localized directories.
- Boundary enforcement is enabled by default.
- Boundary enforcement can be explicitly disabled only with visible warnings.
- Shared framework files are treated as glue, not feature-owned implementation.
- `feature:list`, `feature:inspect`, `feature:map`, and `verify features` exist and produce deterministic JSON.
- Existing context/spec validation commands support the new layout.
- Legacy `docs/features/` compatibility is handled deterministically.
- Tests pass.
- Coverage gate passes.
- `spec:validate --json` passes.
- `verify context --json` passes.
- `context check-alignment --feature=feature-system --json` passes.
- Implementation log entry is appended to `Features/implementation.log`.

## Implementation Guidance

Prefer incremental migration over a large physical rewrite.

Recommended order:

1. Add feature path resolver.
2. Add feature manifest loader.
3. Add feature inventory service.
4. Add CLI commands for list/inspect/map.
5. Add boundary verifier.
6. Update spec validation path discovery.
7. Update context verification path discovery.
8. Add legacy compatibility diagnostics.
9. Migrate the feature-system context files first.
10. Add tests and stabilize deterministic output.
11. Only then consider moving existing feature source/tests into localized directories.

## Design Notes

This spec intentionally does not require every existing runtime class to move immediately.

The first goal is to establish canonical ownership, validation, mapping, and enforcement. Physical migration of existing source code can happen in follow-up specs once ownership is visible and testable.

This is expected to reduce token usage significantly because agents can load:

```text
Features/<FeaturePascalName>/
```

instead of searching across:

```text
docs/features/
src/
tests/
docs/specs/
docs/generated/
```

for every feature-related task.
