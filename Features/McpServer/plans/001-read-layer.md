# Implementation Plan: 001-read-layer

## Scope
- Implement a deterministic, read-only MCP server surface for Foundry.
- Add CLI entrypoint `mcp:serve`.
- Provide stable tools: `explain_target`, `inspect_graph`, `list_packs`, `explain_pack`, `doctor`, `list_examples`.
- Reuse existing CLI/read models (no duplicate business logic).
- Add unit/integration coverage and keep context aligned.

## Steps
1. Implement MCP runtime primitives (`MCPServer`, `ToolRegistry`, tool handlers) with deterministic JSON wrapper output.
2. Add `mcp:serve` CLI command supporting startup manifest and deterministic one-shot tool invocation for testability.
3. Wire command into CLI app registration and API-surface classification/help metadata.
4. Add focused unit tests for tool registry/handlers and integration tests for command behavior and CLI parity.
5. Update `mcp-server` state/spec/decisions context to reflect implemented behavior.
6. Append implementation-log entry and run strict validation gates.
