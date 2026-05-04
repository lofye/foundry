# Feature Spec: feature-system

## Purpose

Define canonical feature workspace boundaries under `Features/` with deterministic compatibility for legacy `docs/features/` paths.

## Goals

- Provide deterministic `feature:list`, `feature:inspect`, `feature:map`, and `verify features` CLI surfaces.
- Treat `Features/implementation.log` as the canonical implementation ledger path.
- Support canonical `Features/*/specs/` and `Features/*/plans/` in spec validation.
- Keep migration-compatible behavior for legacy `docs/features/*` inputs.

## Non-Goals

- Do not physically migrate all framework runtime/test code in one step.
- Do not remove legacy `docs/features/` compatibility in this step.

## Constraints

- Output ordering must remain deterministic and stable.
- Canonical and legacy duplicate definitions must emit deterministic diagnostics.
- Boundary enforcement defaults to enabled and disabled mode must emit visible warnings.

## Expected Behavior

- `feature:list` returns deterministic feature rows from canonical and legacy sources.
- `feature:inspect <feature>` returns context and directory mapping with deterministic dependency order.
- `feature:map` returns deterministic owned path maps.
- `verify features` reports boundary/duplication issues and enforcement status.
- `spec:validate` validates canonical `Features/*/specs` and `Features/*/plans` paths.
- Active-spec implementation logging uses `Features/implementation.log` when canonical workspace is present.

## Acceptance Criteria

- Canonical `Features/` workspace is discoverable and preferred.
- New feature-system CLI surfaces are available and deterministic.
- Canonical/legacy duplicate detection reports `FEATURE_DUPLICATE_CANONICAL_AND_LEGACY`.
- Spec validation supports canonical `Features/*` specs and plans.
- Canonical implementation ledger path is recognized as `Features/implementation.log`.

## Assumptions

- Additional migration specs will progressively move more feature-owned code into localized feature directories.
