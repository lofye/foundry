# Feature: mcp-server

## Purpose

- Record the current repository state for Foundry's deterministic, read-only MCP server surface.

## Current State

- `foundry mcp:serve` is implemented and callable locally.
- MCP startup manifest includes the canonical tool list:
  - `explain_target`
  - `inspect_graph`
  - `list_packs`
  - `explain_pack`
  - `doctor`
  - `list_examples`
- MCP tool responses use the canonical wrapper shape:
  - `{"tool":"<name>","data":{...}}`
- `explain_target` reuses canonical explain behavior.
- `inspect_graph` reuses canonical graph inspection behavior.
- `list_packs` reflects installed pack state deterministically.
- `explain_pack` reuses canonical pack explain behavior.
- `doctor` matches read-only doctor diagnostics behavior.
- `list_examples` reflects canonical example catalog behavior.
- All V1 tools return valid deterministic JSON.
- Tool outputs preserve parity with existing CLI read surfaces.
- Pack-aware data is visible through MCP read tools.
- No write-capable MCP operations are registered in V1.

## Open Questions

- Whether future MCP transport support should include explicit TCP mode in addition to stdio is unresolved.
- Which additional read surfaces should be promoted into first-class MCP tools after V1 remains unresolved.

## Next Steps

- Expand MCP tool coverage only through deterministic CLI parity mappings.
- Preserve read-only behavior until an explicit mutation-surface execution spec is promoted and approved.
