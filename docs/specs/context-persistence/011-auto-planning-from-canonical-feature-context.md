# Execution Spec: context-persistence/011-auto-planning-from-canonical-feature-context

## Feature
- context-persistence

## Purpose
- Add deterministic auto-planning so Foundry can generate the next bounded execution spec from canonical feature context.
- Complete the loop in which canonical context drives planning, execution specs guide implementation, implementation updates context, and updated context drives future planning.

## Scope
- Add `foundry plan feature <feature>`
- Reuse canonical context inputs:
    - `docs/features/<feature>.spec.md`
    - `docs/features/<feature>.md`
    - `docs/features/<feature>.decisions.md`
- Generate one next bounded execution spec under:
    - `docs/specs/<feature>/<NNN-name>.md`
- Reuse existing context infrastructure where practical:
    - path resolution
    - validators
    - doctor
    - alignment
    - execution-spec path conventions
- Return deterministic text and JSON output
- Add PHPUnit coverage

## Constraints
- Keep canonical feature context authoritative for planning inputs.
- Keep generated execution specs secondary to canonical feature truth.
- Reuse existing readiness, validation, and path logic rather than duplicating it.
- Keep planning narrow and bounded to the next coherent work step.
- Fail clearly when canonical context is missing, malformed, or unusable.
- Preserve deterministic numbering, slugging, file generation, and output ordering.
- Preserve separation between planning and execution.

## Requested Changes
- Add `foundry plan feature <feature>`
- Create:
    - `src/CLI/Commands/PlanFeatureCommand.php`
    - `src/Context/ContextPlanningService.php`
    - `src/Context/ExecutionSpecPlanner.php`
    - `src/Context/PlanResult.php`
- Implement deterministic execution-spec generation under:
    - `docs/specs/<feature>/<NNN-name>.md`
- Determine the next sequence number deterministically from existing execution specs in that feature directory
- Generate a stable kebab-case slug for the new execution spec
- Write execution-spec content using the canonical execution-spec structure
- Return stable JSON with:
    - `feature`
    - `status`
    - `can_proceed`
    - `requires_repair`
    - `spec_id`
    - `spec_path`
    - `actions_taken`
    - `issues`
    - `required_actions`

## Non-Goals
- Do not execute the generated spec automatically.
- Do not add `plan spec` or multi-feature planning.
- Do not generate a broad roadmap.
- Do not rewrite the canonical feature spec.
- Do not rewrite or compact the decision ledger.
- Do not bypass doctor, alignment, or readiness semantics.
- Do not add prompt-only planning detached from canonical context.

## Canonical Context
- Canonical feature spec: `docs/features/context-persistence.spec.md`
- Canonical feature state: `docs/features/context-persistence.md`
- Canonical decision ledger: `docs/features/context-persistence.decisions.md`

## Authority Rule
- This execution spec adds planning as a bounded derivative of canonical feature context.
- Generated execution specs are bounded work orders only.
- Generated execution specs MUST NOT override the canonical feature spec.
- If planning detects that intended behavior has changed, it must fail clearly rather than rewriting canonical feature truth.

## Completion Signals
- `foundry plan feature <feature>` exists and works deterministically
- planning consumes canonical feature context only
- planning generates a new execution spec under the canonical feature-scoped directory
- the next sequence number is correct and deterministic
- generated execution spec content matches the required structure
- blocked planning returns deterministic `issues` and `required_actions`
- generated execution specs are usable by `implement spec`
- all tests pass

## Post-Execution Expectations
- Generated execution specs should be immediately usable by:
    - `foundry implement spec <feature>/<NNN-name>`
- Planning should not mutate canonical feature spec, feature state, or decision ledger
- Planning should fail closed when context is structurally invalid or unusable
- After implementation of this spec, `context-persistence` should remain green under:
    - `context doctor`
    - `context check-alignment`
    - `inspect context`
    - `verify context`

## Implementation Notes
- `plan feature` should support:
    - `foundry plan feature <feature>`
    - `foundry plan feature <feature> --json`
- Optional flags are allowed only if they do not complicate the initial design
- Planning should typically produce one bounded next step
- A very small number of tightly scoped next steps is acceptable only if required by the context and still deterministic
- Prefer delegation into shared context services over bespoke orchestration logic
- Generated execution specs should use this structure:

    - `# Execution Spec: <feature>/<NNN-name>`
    - `## Feature`
    - `## Purpose`
    - `## Scope`
    - `## Constraints`
    - `## Requested Changes`
    - `## Non-Goals`
    - `## Canonical Context`
    - `## Authority Rule`
    - `## Completion Signals`
    - `## Post-Execution Expectations`

## JSON Contract
Use this stable top-level shape:

```json
{
  "feature": "blog",
  "status": "planned|blocked",
  "can_proceed": true,
  "requires_repair": false,
  "spec_id": "blog/003-add-rss",
  "spec_path": "docs/specs/blog/003-add-rss.md",
  "actions_taken": [
    "generated execution spec"
  ],
  "issues": [],
  "required_actions": []
}
```

Requirements:
•	deterministic ordering
•	stable keys
•	no timestamps
•	consistent with other context-driven commands

## Test Requirements

Unit tests:
•	next execution spec number is determined correctly
•	execution spec slug generation is deterministic
•	bounded requested changes are generated from simple context gaps
•	planning is blocked when required context is missing or unusable
•	result shape is stable

Integration tests:
•	plan feature <feature> generates the next execution spec file
•	generated spec uses canonical directory structure
•	blocked feature returns correct blocked result
•	generated spec content matches required structure
•	generated spec is usable by implement spec
•	output is deterministic and stable

## Final Instruction

Implement auto-planning as the next bounded step in the context-driven execution system.

Planning must:
•	consume canonical context
•	generate deterministic execution specs
•	respect readiness and enforcement boundaries
•	remain narrow, explicit, and reusable

Do not generate vague plans.
Do not bypass canonical context.
Do not turn planning into execution.