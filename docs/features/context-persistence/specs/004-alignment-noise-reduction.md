# Execution Spec: 004-alignment-noise-reduction

Implement Foundry Master Spec 35D3A — Alignment Noise Reduction and Grounding Refinement

Objective

Refine the 35D3 alignment engine so it remains deterministic but produces concise, trustworthy results on real feature context.

Implement:
- deduplication of repeated alignment issues
- improved deterministic normalization for comparing spec bullets to state tracking bullets
- better grounding of obvious implementation/status phrases
- reduction of repetitive unsupported_state_claim + missing_decision_reference pairs
- PHPUnit coverage

Use the existing 35D3 alignment system.
Do NOT add fuzzy semantic inference, LLM-based matching, inspect integration, verify integration, AGENTS updates, or refusal logic.

---

Scope

Refine:
- AlignmentChecker
- alignment issue generation
- required_actions generation only if needed to stay concise and correct

Do not change the command surface.

---

Goals

- reduce repeated duplicate issues
- make context-persistence a realistic valid test case
- keep alignment deterministic and explainable
- preserve current issue codes where possible
- avoid broadening into semantic guesswork

---

Non-Goals

This spec does not:
- introduce advanced natural language inference
- add new commands
- change doctor behavior
- implement inspect context
- implement verify context
- update AGENTS.md or APP-AGENTS.md

---

Required Behavior

1. Repeated issues with the same effective meaning should be deduplicated.
2. Obvious equivalent phrasing between spec and state should be treated as grounded when deterministic normalization can justify it.
3. Repetitive unsupported_state_claim and missing_decision_reference pairs should be reduced so a single underlying mismatch does not produce excessive noise.
4. Real mismatches must still be reported.

---

Constraints

- deterministic only
- no LLM inference
- no hidden heuristics
- no vague semantic matching
- preserve explainability

---

Tests (PHPUnit)

Unit tests
- repeated untracked requirement issues are deduplicated
- repeated unsupported state claims are deduplicated or grouped cleanly
- obvious normalized phrasing matches are treated as grounded
- real mismatches still produce mismatch

Integration tests
- context-persistence no longer produces a wall of duplicate issues
- alignment output remains deterministic
- issue codes remain stable where possible

---

Acceptance Criteria

The work is complete only when:
- alignment output is materially less noisy
- context-persistence becomes a trustworthy real-world alignment case
- deterministic behavior is preserved
- all added tests pass

---

Final Instruction

Refine the 35D3 alignment engine so it is trustworthy enough to support 35D4.

Do not add semantic guesswork.
Reduce noise without weakening real mismatch detection.
