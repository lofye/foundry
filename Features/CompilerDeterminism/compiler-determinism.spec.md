# Feature Spec: compiler-determinism

## Purpose
- Ensure compiler outputs are deterministic when inputs and explicitly controlled environmental factors are the same.
- Prevent nondeterministic metadata from making equivalent compiler runs diverge unnecessarily.
- Make determinism guarantees explicit enough for tests, caching, and downstream tooling to rely on them safely.

## Goals
- Define deterministic compiler behavior for identical inputs under controlled time.
- Allow tests to freeze time so deterministic comparisons can include metadata fields safely.
- Preserve production behavior that uses real current UTC time by default.
- Prevent timestamp-derived metadata from causing flaky compiler tests.

## Non-Goals
- Do not remove timestamps from compiler metadata entirely.
- Do not require production compiles to use fixed time.
- Do not redesign the entire compiler architecture in this feature.
- Do not broaden this into general runtime determinism outside compiler-owned outputs.

## Constraints
- Determinism guarantees must remain explicit and testable.
- Production behavior must remain unchanged by default.
- Time control must be injectable rather than hidden in global state.
- Compiler outputs must remain trustworthy for cache and projection comparisons.
- Tests must not depend on stripping important metadata fields just to pass.

## Expected Behavior
- Compiler-owned timestamps are obtained through an injectable clock abstraction rather than direct wall-clock calls inside compiler logic.
- When the same compiler inputs and the same explicit clock value are used, repeated compiles produce identical graph/projection outputs.
- Timestamp-derived metadata such as build identifiers remain stable when source input and controlled time are the same.
- When no fixed time is supplied, production compiles continue to use the real current UTC time.

## Acceptance Criteria
- Graph/compiler tests can inject fixed time and compare full outputs directly.
- Determinism-related tests do not fail because wall-clock time advanced between equivalent compile runs.
- Production compiler behavior remains unchanged by default.
- Repeated test runs no longer show timestamp-related flakiness for deterministic compile comparisons.

## Assumptions
- Future compiler determinism work may extend beyond time handling into other environment-derived metadata.
- The compiler may continue to emit observability/build metadata as long as its behavior is explicit and controllable in tests.
