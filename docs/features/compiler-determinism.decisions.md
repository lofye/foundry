### Decision: create compiler-determinism as a standalone feature
Timestamp: 2026-04-17T10:00:00-04:00

**Context**
- A flaky graph compiler test revealed that logically identical compile runs could diverge because compiler metadata depended directly on wall-clock time.
- The fix established a clear invariant around deterministic compiler output under controlled time, but that invariant did not fit cleanly under `context-persistence`, `execution-spec-system`, or `cli-experience`.

**Decision**
- Create a standalone feature named `compiler-determinism`.
- Track compiler determinism guarantees and related future work under this feature, starting with controlled-time graph compilation.

**Reasoning**
- Compiler determinism is a real system-level concern with its own invariants, tests, and future growth path.
- A dedicated feature keeps determinism contracts explicit without diluting unrelated feature boundaries.
- This makes future compiler-determinism changes easier to reason about, verify, and sequence.

**Alternatives Considered**
- Record the fix only as an implementation detail without a dedicated feature.
- Fold compiler determinism into `execution-spec-system`.
- Treat the issue as only a flaky test concern rather than a feature-level invariant.

**Impact**
- Compiler determinism now has a dedicated canonical spec, state document, and decision ledger.
- Future compiler-determinism work can be tracked coherently under one feature.

**Spec Reference**
- Purpose
- Goals
- Constraints
