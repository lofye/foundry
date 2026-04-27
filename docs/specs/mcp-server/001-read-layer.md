# 001-foundry-mcp-server-read-only

## Purpose

Introduce a deterministic, read-only Model Context Protocol (MCP) server for Foundry that exposes structured, machine-consumable tooling for introspection, explainability, and diagnostics.

This provides a standardized control surface for LLM agents without replacing the CLI.

---

## Feature

`mcp-server`

---

## Goals

1. Provide structured access to Foundry internals:
   - graph
   - packs
   - explain system
   - diagnostics
2. Mirror existing CLI capabilities where possible.
3. Ensure deterministic, testable responses.
4. Remain strictly read-only in V1.
5. Provide a stable foundation for future agent-based automation.

---

## Non-Goals

- Do not support generate or mutation operations.
- Do not integrate marketplace functionality.
- Do not introduce authentication or remote access complexity.
- Do not duplicate existing CLI or explain logic.

---

## Core Principle

MCP is a structured interface to the CLI and internal read models.

It MUST NOT replace or diverge from existing behavior.

---

## Server Entry

### Command

```bash
foundry mcp:serve
```

### Behavior

- Starts MCP server (stdio or TCP)
- Registers tool surface
- Outputs capability manifest on startup

---

## Tool Surface

All tools MUST return deterministic JSON.

### explain_target

Input:

```json
{
  "target": "string"
}
```

Behavior:

- Delegates to existing explain system

Output:

- Structured JSON equivalent of `foundry explain`

---

### inspect_graph

Returns:

- nodes
- edges
- pack contributions

---

### list_packs

Returns:

```json
[
  {
    "name": "string",
    "version": "string"
  }
]
```

---

### explain_pack

Input:

```json
{
  "name": "string"
}
```

Returns:

- schemas
- commands
- routes
- guards
- extensions

---

### doctor

Equivalent to:

```bash
foundry doctor --json
```

---

### list_examples

Returns:

- available example applications/templates

---

## Output Contract

All responses MUST:

- be JSON
- follow stable schemas
- be deterministic
- contain no extraneous fields

Canonical wrapper:

```json
{
  "tool": "string",
  "data": {}
}
```

---

## CLI Parity Rules

Each MCP tool MUST map to:

- an existing CLI command OR
- a deterministic read model derived from internal state

No divergence allowed.

---

## Architecture

Components:

- `MCPServer`
- `ToolRegistry`
- `ToolHandler` classes

### Registration Example

```php
$registry->register('list_packs', ListPacksHandler::class);
```

Rules:

- Tool names MUST be stable
- Handlers MUST be stateless
- Registration MUST be deterministic

---

## Determinism Requirements

- identical input MUST produce identical output
- no randomness
- no hidden state
- no environment-dependent variation

---

## Safety Model

V1 is strictly read-only.

Forbidden:

- file system mutation
- code generation
- state changes
- network writes

Allowed:

- reading internal state
- invoking CLI-equivalent read operations

---

## Pack Awareness

MCP MUST:

- detect installed packs
- include pack contributions in:
  - graph inspection
  - explain results
  - diagnostics

---

## Explain Integration

MCP MUST reuse the canonical explain engine.

Rules:

- no duplicated logic
- no parallel explain implementation
- must call existing explain system

---

## Testing Requirements

Tests MUST cover:

1. Each tool returns valid JSON
2. Output schema correctness
3. Deterministic behavior
4. Parity with CLI outputs
5. Pack awareness correctness
6. Failure handling

---

## Dev Experience

Running:

```bash
foundry mcp:serve
```

MUST output:

```json
{
  "name": "foundry-mcp",
  "tools": [
    "explain_target",
    "inspect_graph",
    "list_packs",
    "explain_pack",
    "doctor",
    "list_examples"
  ]
}
```

---

## Compatibility Requirements

- CLI behavior MUST remain unchanged
- MCP MUST be additive
- Existing systems MUST not be modified to support MCP beyond read access

---

## Acceptance Criteria

- MCP server runs locally
- all tools return valid deterministic JSON
- outputs match CLI equivalents
- packs are visible and explainable
- no write operations exist
- test coverage passes
- strict coverage gate exits 0

---

## Done Means

Foundry exposes a deterministic, machine-native control surface that enables LLM agents to introspect, explain, and analyze the system safely.

This establishes the foundation for future:
- generate operations
- marketplace integration
- advanced automation
