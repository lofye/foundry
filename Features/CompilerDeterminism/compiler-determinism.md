# Feature: compiler-determinism

## Purpose
- Ensure compiler outputs are deterministic when inputs and explicitly controlled environmental factors are the same.

## Current State
- Compiler-owned timestamps are now obtained through an injectable `Clock` abstraction rather than direct wall-clock calls inside compiler logic.
- Graph/compiler tests can inject fixed time and compare full outputs directly.
- When the same compiler inputs and the same explicit clock value are used, repeated compiles produce identical graph and projection outputs.
- Timestamp-derived metadata such as build identifiers remain stable when source input and controlled time are the same.
- Production compiler behavior continues to use the real current UTC time by default.
- Determinism-related tests no longer fail because wall-clock time advanced between equivalent compile runs.
- Repeated deterministic compile comparisons no longer show timestamp-related flakiness.

## Open Questions
- Which additional compiler-owned metadata fields, if any, should eventually be brought under the same determinism guarantees?
- Should future compiler determinism work define a broader controlled-environment abstraction beyond time alone?

## Next Steps
- Keep compiler determinism expectations explicit in future compiler-facing tests.
- Add future compiler-determinism specs if additional nondeterministic metadata sources are discovered.
