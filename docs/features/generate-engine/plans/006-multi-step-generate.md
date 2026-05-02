# Implementation Plan: 006-multi-step-generate

## Objective

Define the implementation approach and expected outcomes for 006-multi-step-generate.

## Scope

Describe included and excluded work needed to implement 006-multi-step-generate.

## Inputs

- Active execution spec path for 006-multi-step-generate.
- Current feature context (spec/state/decisions).

## Implementation Steps

1. Validate context and alignment before coding.
2. Implement the smallest deterministic change set in source-of-truth files.
3. Add or update tests covering required behavior.
4. Realign feature documentation and decision history append-only if behavior changed.

## Verification

Run required checks:

```bash
php bin/foundry verify context --feature=<feature-name> --json
php bin/foundry context check-alignment --feature=<feature-name> --json
php bin/foundry spec:validate --json
```

## Risks And Mitigations

- Risk: spec/state/code drift.
- Mitigation: enforce context verification and alignment checks before completion.
