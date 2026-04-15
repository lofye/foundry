# Execution Spec: 006-prevent-framework-spec-implementation-from-scaffolding-app-features

## Feature
- execution-spec-system

## Purpose
- Prevent framework-internal execution specs from creating accidental app feature scaffolds under `app/features/*`.
- Keep framework-feature implementation work inside framework-owned locations such as `src/`, `tests/`, `docs/`, and `stubs/`.
- Stop `implement spec` from routing framework-internal execution specs through the generic application feature-generation pipeline.

## Scope
- Add a guard or routing rule to the framework-repo `implement spec` path.
- Prevent execution specs for framework-internal features from calling the generic app-feature scaffold flow.
- Ensure no files are created under `app/features/<feature>/` for framework-internal execution-spec work.
- Keep this focused on preventing misplaced output, not on redesigning the whole implementation system.

## Constraints
- Keep framework-internal features out of `app/features/*`.
- Keep deterministic behavior.
- Do not silently create demo/smoke-app feature scaffolds for framework features.
- Reuse existing execution plumbing where practical.
- Prefer the smallest clear fix that blocks the wrong pipeline from running.
- Do not broaden this into a full redesign of `implement spec`.

## Requested Changes

### 1. Block the Wrong Pipeline for Framework-Internal Features

Update `implement spec` execution flow so that framework-internal execution specs do not route into the generic app-feature scaffold path.

Currently, the wrong path is:

- `ImplementSpecCommand`
- `ContextExecutionService::executeSpec(...)`
- `ContextExecutionService::execute(...)`
- `buildExecutionInput()`
- `executeFeatureWork()`
- `createFeatureFromContext()` / `modifyFeatureFromContext()`
- `FeatureGenerator`
- writes under `app/features/<feature>/`

This must not happen for framework-internal features such as `execution-spec-system`.

### 2. Define the Framework-Internal Behavior

For framework-internal execution specs, `implement spec` must either:

- use a dedicated framework-internal implementation path, or
- stop before generic scaffold generation with a deterministic, explicit result

Either approach is acceptable in this spec, but the system must no longer create app-feature scaffolds for framework features.

### 3. Protect `app/features/*` from Framework Spec Execution

Add a guard that prevents `implement spec` from creating files under:

```text
app/features/<feature>/
```

when the spec belongs to a framework-internal feature.

This guard must be deterministic and explicit.
Do not rely on “cleanup later” behavior.

### 4. Remove the Accidental Scaffold Output

Delete the accidental scaffold directory created for this bug:

```text
app/features/execution-spec-system/
```

This directory and its contents are misplaced output and must not remain in the framework repo as the implementation of the `execution-spec-system` feature.

No contents from that directory need relocation first.

### 5. Preserve Correct Framework Destinations

Framework-internal implementation work must continue to land in appropriate framework-owned locations such as:

- `src/`
- `tests/`
- `docs/`
- `stubs/`

This spec does not require a brand-new abstraction for every framework feature, but it does require that framework execution-spec work stop using the generic application scaffold destination.

### 6. Keep the External Command Honest

If `implement spec` cannot yet meaningfully perform framework-internal implementation through a dedicated path, it must fail clearly rather than generating misplaced app-feature files.

Do not report success while creating framework-internal output in the wrong location.

### 7. Tests

Add focused coverage proving:

- framework-internal execution specs do not create files under `app/features/*`
- `execution-spec-system` no longer scaffolds `app/features/execution-spec-system/`
- accidental framework-to-app scaffold routing is blocked deterministically
- existing valid application-feature generation behavior still works where intended
- all related execution-path tests still pass

## Non-Goals
- Do not redesign the full generic feature-generation system.
- Do not move framework features into the demo/smoke app.
- Do not keep accidental app-feature scaffolds just because they compile.
- Do not broaden this into a general repo-cleanup spec outside this specific bug.

## Canonical Context
- Canonical feature spec: `docs/features/execution-spec-system.spec.md`
- Canonical feature state: `docs/features/execution-spec-system.md`
- Canonical decision ledger: `docs/features/execution-spec-system.decisions.md`

## Authority Rule
- Framework-internal execution specs must not be implemented by scaffolding files into `app/features/*`.
- The framework repo demo/smoke app is not the destination for framework feature implementations.
- Misplaced scaffold output must be blocked or cleaned up explicitly.

## Completion Signals
- `implement spec` no longer creates `app/features/execution-spec-system/` or similar framework-feature app scaffolds.
- The accidental `app/features/execution-spec-system/` directory is removed.
- Framework-internal execution-spec work no longer routes through the generic app-feature scaffold path.
- Behavior remains deterministic.
- All tests pass.

## Post-Execution Expectations
- Framework-internal spec execution no longer pollutes `app/features/*`.
- `implement spec` becomes safer and more trustworthy in the framework repo.
- Framework feature work stays in framework-owned locations only.
