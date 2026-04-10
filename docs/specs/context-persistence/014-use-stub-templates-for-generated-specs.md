# Execution Spec: context-persistence/014-use-stub-templates-for-generated-specs

## Feature
- context-persistence

## Purpose
- Enforce a single canonical structure for all generated execution specs via stub templates.
- Eliminate structural drift by separating content generation from document structure.

## Scope
- Introduce a stub template for execution specs.
- Refactor planner output rendering to use the stub.
- Ensure all generated specs are produced exclusively through the stub.

## Constraints
- Template must be deterministic and static.
- No runtime templating engines (e.g., Blade, Twig).
- Planner remains responsible for content only (not formatting).
- Canonical execution spec structure must remain unchanged.
- No modification of canonical feature documents.

## Requested Changes

### 1. Add Stub Template
Create:
- `stubs/specs/execution-spec.stub.md`

Template must define the full canonical structure, including:
- Title (Execution Spec: <feature>/<id>-<slug>)
- Feature
- Purpose
- Scope
- Constraints
- Requested Changes
- Non-Goals
- Completion Signals
- Post-Execution Expectations

Use simple placeholder tokens, e.g.:
- {{feature}}
- {{spec_id}}
- {{slug}}
- {{purpose}}
- {{scope}}
- {{constraints}}
- {{requested_changes}}

### 2. Replace Inline Assembly
- Remove any string concatenation or manual markdown assembly from planner.
- Replace with:
  - load stub
  - inject placeholders
  - render final markdown

### 3. Deterministic Rendering
- Placeholder substitution must be:
  - order-stable
  - whitespace-stable
  - newline-stable
- No conditional sections that alter structure.

### 4. Canonical Output Enforcement
- Generated specs must match stub structure exactly.
- Section ordering must be identical across all outputs.

### 5. Test Coverage
Add tests to assert:
- Generated specs match stub structure exactly.
- Repeated runs produce identical output.
- Stub changes propagate without requiring planner changes.
- No extra or missing sections in generated specs.

## Non-Goals
- Do not change execution spec schema or section definitions.
- Do not introduce dynamic or logic-based templating.
- Do not improve planner content quality (handled in 012).
- Do not alter canonical feature context files.

## Canonical Context
- docs/features/context-persistence.spec.md
- docs/features/context-persistence.md
- docs/features/context-persistence.decisions.md

## Authority Rule
- Stub defines structure.
- Planner defines content.
- Final output is a deterministic merge of both.

## Completion Signals
- All generated specs use the stub template.
- No structural differences between generated specs.
- Output is byte-for-byte identical for identical inputs.
- All tests pass.

## Post-Execution Expectations
- Execution spec structure is locked and centrally controlled.
- Future changes to structure require only stub updates.
- Planner evolves safely without risking format drift.
