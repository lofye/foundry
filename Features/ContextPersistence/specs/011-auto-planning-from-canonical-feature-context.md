# Execution Spec: 011-auto-planning-from-canonical-feature-context

## Feature
- context-persistence

## Purpose
- Introduce deterministic auto-planning so Foundry can generate the next bounded execution spec from canonical feature context.
- Complete the loop where canonical context drives planning, execution specs guide implementation, implementation updates context, and updated context drives future planning.

## Scope
- Add CLI command:
    - `foundry plan feature <feature>`
    - `foundry plan feature <feature> --json`
- Load and use canonical inputs:
    - `docs/features/<feature>/<feature>.spec.md`
    - `docs/features/<feature>/<feature>.md`
    - `docs/features/<feature>/<feature>.decisions.md`
- Generate one bounded execution spec under:
    - `docs/features/<feature>/specs/<id>-<slug>.md`
- Determine next sequence number deterministically
- Generate a stable kebab-case slug
- Write execution spec using canonical structure
- Return deterministic output (text and JSON)
- Add PHPUnit coverage

## Constraints
- Keep canonical feature context authoritative for all planning inputs.
- Keep generated execution specs secondary to canonical feature truth.
- Reuse existing context infrastructure (doctor, alignment, path resolution, validators) where possible.
- Keep planning bounded to the next coherent work step.
- Preserve deterministic numbering, slugging, and output ordering.
- Fail clearly when canonical context is missing, malformed, or unusable.
- Preserve separation between planning and execution.

## Requested Changes
- Add `PlanFeatureCommand`
    - orchestrates planning flow
    - enforces preconditions
    - supports `--json`
- Add `ContextPlanningService`
    - loads canonical context
    - runs readiness checks
    - coordinates planning + file creation
- Add `ExecutionSpecPlanner`
    - identifies gaps between:
        - intended behavior
        - current state
        - prior decisions
    - derives next bounded work step
    - determines next sequence number
    - generates deterministic slug
    - builds execution spec content
- Add `PlanResult`
    - contains:
        - status
        - can_proceed
        - requires_repair
        - spec_id
        - spec_path
        - actions_taken
        - issues
        - required_actions
- Implement execution spec generation:
    - create feature directory if missing
    - never overwrite existing specs
    - write file deterministically

## Non-Goals
- Do not execute the generated spec automatically.
- Do not support `plan spec` or multi-feature planning.
- Do not generate a full roadmap.
- Do not rewrite canonical feature spec.
- Do not rewrite decision ledger.
- Do not bypass context validation or readiness checks.
- Do not rely on prompt-only or chat-derived planning.

## Canonical Context
- Canonical feature spec: `docs/context-persistence/context-persistence.spec.md`
- Canonical feature state: `docs/context-persistence/context-persistence.md`
- Canonical decision ledger: `docs/context-persistence/context-persistence.decisions.md`

## Authority Rule
- Planning is derived strictly from canonical feature context.
- Generated execution specs are bounded work orders only.
- Generated execution specs must not override canonical feature intent.
- When intent ambiguity or conflict is detected, planning must fail clearly rather than infer new behavior.

## Completion Signals
- `foundry plan feature <feature>` generates a new execution spec file
- next sequence number is correct and deterministic
- slug generation is stable
- generated spec matches required structure
- planning fails cleanly when context is unusable
- generated spec is usable by `implement spec`
- all tests pass

## Post-Execution Expectations
- Generated execution specs are immediately usable via:
    - `foundry implement spec <feature>/<id>-<slug>`
- Planning does not modify:
    - canonical feature spec
    - feature state
    - decision ledger
- Context validation remains green:
    - `context doctor`
    - `context check-alignment`
    - `verify context`

## JSON Contract
```json
{
  "feature": "blog",
  "status": "planned|blocked",
  "can_proceed": true,
  "requires_repair": false,
  "spec_id": "blog/003-add-rss",
  "spec_path": "docs/blog/specs/003-add-rss.md",
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

### Unit tests
•	next execution spec number is determined correctly
•	slug generation is deterministic
•	bounded requested changes are derived from simple context gaps
•	planning is blocked when required context is missing or invalid
•	result shape is stable

### Integration tests
•	plan feature <feature> generates next execution spec file
•	generated spec uses correct directory structure
•	blocked feature returns correct result
•	generated spec content matches required structure
•	generated spec is executable via implement spec
•	output is deterministic

## Final Instruction

Implement auto-planning as the next bounded step in the context-driven execution system.

Planning must:
•	consume canonical context
•	derive the next bounded work step
•	generate deterministic execution specs
•	respect readiness and enforcement boundaries
•	remain narrow, explicit, and reproducible

Do not generate vague plans.
Do not bypass canonical context.
Do not expand planning into roadmap generation.
