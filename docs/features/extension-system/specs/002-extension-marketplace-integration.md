# Execution Spec: 002-extension-marketplace-integration

## Purpose

Enable deterministic discovery, installation, and compatibility validation of Foundry extensions (“packs”) via a registry model, allowing the ecosystem to become distributable while preserving stability and reproducibility.

---

## Feature

`extension-system`

---

## Goals

1. Enable discovery of installable extensions.
2. Support deterministic installation of extensions into a repository.
3. Enforce version and compatibility constraints.
4. Provide a foundation for future monetization (Spec 30).
5. Preserve reproducibility and deterministic environments.

---

## Non-Goals

- Do not implement payment processing.
- Do not introduce non-deterministic remote execution.
- Do not allow implicit or auto-updating dependencies.
- Do not bypass existing extension loading mechanisms.
- Do not introduce runtime network dependencies during execution.

---

## Core Concepts

### Extension (Pack)

A versioned, installable unit that extends Foundry capabilities.

Properties:

- name
- version
- description
- compatibility constraints
- distribution source

---

### Registry

A source of discoverable extensions.

Types (V1):

- repository-local registry
- static remote registry (read-only metadata)

---

### Installation

A deterministic process that adds an extension to the repository.

---

## Registry Model

### Registry Entry Shape

```json
{
  "name": "string",
  "version": "string",
  "description": "string",
  "source": {
    "type": "git|archive",
    "location": "string"
  },
  "compatibility": {
    "foundry_version": "string"
  }
}
```

---

## Determinism Rules

- Registry responses MUST be deterministic
- Installed versions MUST be explicit (no ranges in V1)
- No implicit upgrades
- Install results MUST be reproducible

---

## Installation Model

### Command

```bash
foundry extension:install <name> --version=<version>
```

### Behavior

1. Resolve extension from registry
2. Validate compatibility
3. Download source
4. Install into repository-local extensions directory
5. Register extension in manifest

---

## Repository Manifest

Installed extensions MUST be tracked in:

```text
.foundry/extensions.json
```

Shape:

```json
{
  "extensions": [
    {
      "name": "string",
      "version": "string",
      "source": "string"
    }
  ]
}
```

Rules:

- Append-only changes per install/remove
- Deterministic ordering

---

## Compatibility Rules

Installation MUST fail when:

- extension requires incompatible Foundry version
- extension version is not found
- extension conflicts with installed extensions

---

## CLI Behavior

### Discover Extensions

```bash
foundry extension:search
```

### Install Extension

```bash
foundry extension:install <name> --version=<version>
```

### List Installed

```bash
foundry extension:list
```

---

## Inspect Surface Requirements

Inspect MUST expose:

- installed extensions
- versions
- sources
- compatibility status

---

## Verify Surface Requirements

Verify MUST fail when:

- manifest is invalid
- installed extension missing source
- compatibility constraints violated
- duplicate extensions installed

---

## Security Constraints

- No execution of remote code during install beyond retrieval
- No implicit script execution
- All extension loading MUST follow existing extension system rules

---

## Compatibility Requirements

- Existing extension system MUST remain functional
- Marketplace integration MUST be additive
- No breaking changes to extension loading

---

## Tests Required

1. Registry discovery
2. Deterministic install
3. Compatibility enforcement
4. Manifest correctness
5. CLI commands
6. Verify failure cases
7. Backward compatibility

---

## Acceptance Criteria

- Extensions can be discovered
- Extensions can be installed deterministically
- Compatibility is enforced
- Manifest is persisted
- Inspect and verify support extensions
- All tests pass
- Strict coverage gate exits 0

---

## Done Means

Foundry supports a deterministic, installable extension ecosystem with enforceable compatibility and reproducible environments.
