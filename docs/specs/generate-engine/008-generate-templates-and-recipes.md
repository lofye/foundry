# 008-generate-templates-and-recipes

## Purpose

Provide reusable, parameterized templates (“recipes”) for common generation tasks, enabling developers to invoke proven generation patterns with minimal input while preserving determinism and full validation.

---

## Feature

`generate-engine`

---

## Goals

1. Standardize common generation patterns.
2. Reduce repetition in multi-step and single-step generation workflows.
3. Ensure all templates produce valid, deterministic GenerationPlans.
4. Integrate seamlessly with existing generate CLI and validation pipeline.

---

## Non-Goals

- Do not introduce non-deterministic template behavior.
- Do not bypass plan validation.
- Do not create a complex DSL.
- Do not introduce hidden logic inside templates.
- Do not replace raw `generate` usage.

---

## Core Concepts

### Template (Recipe)

A reusable definition that produces a GenerationPlan (or workflow) from parameters.

Properties:

- `template_id`
- `description`
- `parameters`
- `definition`

---

### Template Registry

A deterministic registry of available templates.

Sources (V1):

- repository-local: `.foundry/templates/*.json`

Rules:

- Must be statically discoverable
- Must not depend on runtime state
- Must be loaded deterministically

---

### Parameters

User-provided inputs used to instantiate a template.

Rules:

- Must be explicitly declared
- Must have deterministic defaults or be required
- Must be type-safe (string, number, boolean, array, object)

---

## Template Definition Shape

Templates MUST be defined as JSON.

Canonical shape:

```json
{
  "schema": "foundry.generate.template.v1",
  "template_id": "string",
  "description": "string",
  "parameters": {
    "name": {
      "type": "string",
      "required": true
    }
  },
  "generate": {
    "type": "single|workflow",
    "definition": {}
  }
}
```

---

## Execution Model

1. Load template from registry
2. Validate template structure
3. Validate parameters
4. Resolve parameters into template definition
5. Produce GenerationPlan or WorkflowPlan
6. Execute via existing generate engine
7. Persist records as normal generate/workflow records

---

## Requirements

### Registry

- Templates MUST be discoverable via repository-local registry
- Template IDs MUST be unique within the repository
- Templates MUST be loaded deterministically

### Parameter Resolution

- Parameter substitution MUST be deterministic
- Missing required parameters MUST fail validation
- No implicit parameters allowed

### Integration

- Templates MUST produce valid GenerationPlans or WorkflowPlans
- Output MUST pass full validation pipeline
- No bypass of policy, validation, or execution rules

### Determinism

- Same template + same parameters MUST produce identical plans
- No randomness or external state allowed

---

## CLI Behavior

### New Commands

```bash
foundry generate --template=<template_id>
```

With parameters:

```bash
foundry generate --template=<template_id> --param name=value
```

### Behavior

- Loads template
- Resolves parameters
- Produces plan
- Runs full generate pipeline

### Output

- Must match standard generate output
- Must indicate template source
- Must show resolved parameters

---

## Inspect Surface Requirements

Inspect MUST expose:

- template_id used
- resolved parameters
- resulting plan or workflow

Output MUST be deterministic.

---

## Verify Surface Requirements

Verify MUST fail when:

- template schema is invalid
- required parameters are missing
- parameter types are invalid
- template produces invalid plan
- template resolution is non-deterministic

---

## Compatibility Requirements

- Existing generate behavior MUST remain unchanged
- Templates MUST be additive
- Existing plans MUST remain valid

---

## Tests Required

1. Template loading
2. Parameter validation
3. Deterministic parameter resolution
4. Template → GenerationPlan correctness
5. Template → WorkflowPlan correctness
6. CLI execution with templates
7. Verify failure cases
8. Backward compatibility

---

## Acceptance Criteria

- Templates can be defined and loaded from repository
- Templates can be executed via CLI
- Templates produce valid GenerationPlans or WorkflowPlans
- Deterministic behavior is preserved
- Inspect and verify surfaces support templates
- All tests pass
- Strict coverage gate exits 0

---

## Done Means

Developers can invoke reusable generation patterns safely, deterministically, and consistently through templates integrated into the generate system.
