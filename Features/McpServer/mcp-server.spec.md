# Feature Spec: mcp-server

## Purpose

- Define the deterministic, read-only MCP surface for Foundry introspection and diagnostics.

## Goals

- Expose stable MCP tooling for canonical read operations.
- Preserve CLI parity by delegating to existing read models and command surfaces.
- Keep MCP V1 strictly read-only and deterministic.

## Non-Goals

- No mutation or generate operations in V1.
- No replacement of existing CLI behavior.
- No non-deterministic or hidden-state tool behavior.

## Constraints

- MCP tools must return deterministic JSON for identical inputs.
- MCP tools must map to existing CLI read commands or deterministic read models.
- The MCP surface must not perform filesystem mutation, generation, or state changes.

## Expected Behavior

- `foundry mcp:serve` is available as the MCP entrypoint.
- MCP startup manifest includes canonical tool list:
  - `explain_target`
  - `inspect_graph`
  - `list_packs`
  - `explain_pack`
  - `doctor`
  - `list_examples`
- MCP tool responses use canonical wrapper shape:
  - `{"tool":"<name>","data":{...}}`
- MCP `explain_target` reuses canonical explain behavior.
- MCP `inspect_graph` reuses canonical graph inspection behavior.
- MCP `list_packs` reflects installed pack state deterministically.
- MCP `explain_pack` reuses canonical pack explain behavior.
- MCP `doctor` matches read-only doctor diagnostics behavior.
- MCP `list_examples` reflects canonical example catalog behavior.

## Acceptance Criteria

- MCP server command is implemented and callable locally.
- All V1 tools return valid deterministic JSON.
- Tool outputs preserve parity with existing CLI read surfaces.
- Pack-aware data is visible through MCP read tools.
- No write-capable MCP operations are registered in V1.

## Assumptions

- Future MCP expansion will continue to use promoted execution specs.
- Mutation-capable MCP surfaces require explicit future contract work and are out of scope for this feature state.
