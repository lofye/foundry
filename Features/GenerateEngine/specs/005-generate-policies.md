# Execution Spec: 005-generate-policies

## Feature
- generate-engine

## Purpose
- Introduce a deterministic policy system that governs how `foundry generate` may operate.
- Allow projects and teams to define explicit generation boundaries before plans execute.
- Prevent unsafe, undesired, overly broad, or policy-forbidden changes from being applied silently.
- Integrate policy results into generate validation, interactive review, and persisted plan history.

## Scope
- Add a V1 generate-policy model.
- Add a repository-local policy file.
- Evaluate policy rules against `GenerationPlan` before execution.
- Surface policy warnings and violations in human and JSON output.
- Block execution when policy violations are not explicitly overrideable or not explicitly overridden.
- Persist policy evaluation results in plan history records.
- Keep V1 simple, explicit, deterministic, and testable.

## Constraints
- Do not rely on LLM interpretation of policies.
- Do not introduce a broad policy DSL in V1.
- Do not introduce hidden or implicit policy rules.
- Do not require external services or organization-level hosting.
- Do not silently bypass policy evaluation.
- Do not make policy overrides invisible.
- Do not redesign `GenerateEngine`, `GenerationPlan`, `PlanValidator`, interactive generate, replay, undo, or plan persistence.
- Reuse existing generate validation, plan preview, safety routing, and plan persistence infrastructure where practical.

## Inputs

Expect inputs such as:
- `GenerationPlan`
- plan actions
- action types
- affected file paths
- generation mode (`new|modify|repair`)
- risk level
- feature or module names when available
- graph node metadata when available
- repository-local policy file

If any critical input is missing:
- fail clearly and deterministically
- do not guess policy meaning
- do not silently ignore malformed policy content

## Requested Changes

### 1. Add Generate Policy Storage

Add repository-local generate policy storage under:

```text
.foundry/policies/
```

Primary V1 policy file:

```text
.foundry/policies/generate.json
```

If no policy file exists:
- default behavior should remain backward compatible
- no hidden policy rules should be applied
- output may indicate that no policy file was loaded when relevant

### 2. Define V1 Policy Schema

V1 policy file shape:

```json
{
  "version": 1,
  "rules": []
}
```

Each rule must be explicit, machine-readable, and deterministic.

A rule should include, at minimum:
- stable rule id
- rule type
- outcome or severity
- match criteria
- human-readable description

Recommended V1 shape:

```json
{
  "id": "protect-core-files",
  "type": "deny",
  "description": "Prevent generate from modifying core framework files.",
  "match": {
    "actions": ["delete_file", "update_file"],
    "paths": ["src/Core/**"]
  }
}
```

Codex may refine the internal shape if the implementation needs a better typed model, but the public JSON contract must remain simple and deterministic.

### 3. Support V1 Rule Types

Support these V1 rule types:

#### Deny
Blocks matched plan actions.

Examples:
- deny deleting core files
- deny modifying protected directories
- deny high-risk actions

#### Warn
Surfaces non-blocking policy concerns.

Examples:
- warn when more than N files are changed
- warn when a plan touches multiple features

#### Require
Requires a condition to be true for a matched plan.

Examples:
- require tests when new feature files are created
- require docs updates when public API files are modified

#### Limit
Limits plan scope.

Examples:
- maximum file count
- maximum action count
- maximum affected feature count

V1 may implement `allow` only if it is needed by the chosen rule model. If implemented, allow rules must not create ambiguous precedence.

### 4. Define Deterministic Matching

Policy matching must support the smallest practical set of explicit match criteria, including:

- action type
- file path patterns
- generation mode (`new|modify|repair`)
- risk level
- feature/module name when available
- graph node type when available

V1 path matching should use a deterministic, documented pattern strategy. Keep it simple, such as:
- exact path
- prefix-style glob
- `**` for nested path matching if already supported or easy to implement safely

Matching must be:
- deterministic
- explicit
- testable
- independent of runtime randomness

### 5. Add Policy Engine

Introduce a generate policy evaluation component responsible for:

- loading `.foundry/policies/generate.json`
- validating policy structure
- evaluating rules against a `GenerationPlan`
- returning a deterministic result object containing:
  - loaded policy path
  - policy version
  - warnings
  - violations
  - blocking status
  - matched rule ids
  - affected actions/files

Do not scatter policy checks across unrelated generate code paths.

### 6. Integrate With Generate Flow

Policy evaluation must occur before file writes.

Preferred flow:

```text
GenerationPlan
→ PlanValidator
→ PolicyEngine
→ interactive review / execution decision
```

or another equivalent ordering if implementation requires it, provided:
- policies evaluate before file writes
- policy-denied plans do not execute silently
- policy results are visible before execution

Policy results must be included in the generate result payload.

### 7. Policy Outcomes

Policy evaluation must produce one of:

#### Pass
- no warnings or violations
- plan may proceed

#### Warn
- non-blocking warnings exist
- plan may proceed, but warnings must be surfaced

#### Deny
- blocking violations exist
- plan must not execute unless a valid explicit override is allowed and supplied

### 8. Add Policy Check Mode

Add a policy-check surface for generate.

Preferred command shape:

```bash
foundry generate "<intent>" --mode=<new|modify|repair> --policy-check
```

Behavior:
- build the plan
- validate the plan
- evaluate policies
- output policy result
- do not execute file changes

If JSON is requested, output must be deterministic and machine-readable.

### 9. Add Explicit Override Support

Add an explicit override flag:

```bash
foundry generate "<intent>" --mode=<new|modify|repair> --allow-policy-violations
```

Rules:
- override must be explicit
- override must be surfaced in human and JSON output
- override must be persisted in plan history
- override must not bypass malformed policy handling
- override must not bypass non-overrideable violations if V1 supports non-overrideable policies

If V1 keeps all deny rules overrideable, that must be documented clearly. If non-overrideable rules are supported, the behavior must be deterministic and tested.

### 10. Integrate With Interactive Generate

Interactive generate must surface:
- policy warnings
- policy violations
- matched rule ids
- affected actions/files
- whether the plan is blocked
- whether override is available

Interactive mode may allow the user to:
- reject the plan
- modify the plan to remove offending actions
- explicitly override violations when allowed

Policy-denied plans must not execute from interactive mode without explicit override confirmation.

### 11. Persist Policy Results In Plan History

Persist policy evaluation data into generated plan records, including:
- policy path
- policy version
- policy outcome
- warnings
- violations
- matched rules
- override flag
- override decision metadata if available

This must integrate with the plan persistence work already in `generate-engine/003`.

### 12. Starter Policies

Add optional starter policy examples only if they can be provided without changing default behavior.

Starter policies must be:
- opt-in
- editable
- documented as examples
- not silently active by default

Examples may include:
- prevent deletion of protected directories
- warn on large multi-file changes
- require tests for new feature work

### 13. Output Contract

Human output should include:
- policy status
- warnings
- violations
- matched rules
- override status if used

JSON output should include, at minimum:

```json
{
  "policy": {
    "loaded": true,
    "path": ".foundry/policies/generate.json",
    "version": 1,
    "status": "pass|warn|deny",
    "warnings": [],
    "violations": [],
    "override_used": false
  }
}
```

Exact field placement may follow existing generate output conventions, but policy results must be deterministic and machine-readable.

### 14. Determinism

Policy evaluation must:
- produce stable results for identical plan + policy inputs
- use stable rule ordering
- use stable violation ordering
- avoid timestamps/randomness in policy decisions
- fail clearly on malformed policies

### 15. Tests

Add focused coverage proving:

- valid policy files load deterministically
- malformed policy files fail clearly
- deny rules block execution before file writes
- warning rules surface warnings without blocking
- require rules enforce required conditions
- limit rules enforce plan/action/file count limits
- path/action/mode/risk matching works deterministically
- `--policy-check` evaluates without execution
- `--allow-policy-violations` is explicit, surfaced, and persisted
- interactive generate displays and respects policy results
- plan history persists policy evaluation results
- default behavior remains backward compatible when no policy file exists
- all relevant generate tests still pass

## Non-Goals
- Do not build a complex policy DSL in V1.
- Do not introduce role-based/team permissions in this spec.
- Do not add environment-specific policy resolution in this spec.
- Do not add remote organization policy hosting.
- Do not add policy marketplace/packs in this spec.
- Do not rely on LLM interpretation of policy prose.
- Do not redesign `PlanValidator`.
- Do not replace existing safety routing.

## Canonical Context
- Canonical feature spec: `docs/generate-engine/generate-engine.spec.md`
- Canonical feature state: `docs/generate-engine/generate-engine.md`
- Canonical decision ledger: `docs/generate-engine/generate-engine.decisions.md`

## Authority Rule
- Generate policies are explicit, deterministic constraints over generation plans.
- Policy-denied plans must not execute silently.
- Overrides must be explicit, visible, and persisted.
- Policies are machine-enforced rules, not advisory prose for an LLM to interpret.

## Completion Signals
- `.foundry/policies/generate.json` is supported
- V1 policy schema exists
- policy rules evaluate against `GenerationPlan`
- policy violations can block execution
- warnings are surfaced
- `--policy-check` exists
- explicit policy override exists
- interactive generate surfaces policy results
- plan history persists policy results
- all tests pass

## Post-Execution Expectations
- Foundry generate becomes policy-aware.
- Developers and teams can define deterministic boundaries for code evolution.
- Unsafe or undesired plan actions become visible and blockable before execution.
- Later governance, approval, organization, and marketplace policy systems have a stable foundation.
