# Feature: mcp-server

## Purpose

- Record the current repository state for the planned `mcp-server` feature.

## Current State

- Canonical feature context now exists for `mcp-server` under `docs/mcp-server/`.
- One draft execution spec exists at `docs/mcp-server/specs/drafts/001-read-layer.md`.
- The draft spec describes a future deterministic, read-only MCP server surface for Foundry introspection.
- The current feature state explicitly reports draft planning artifacts without claiming shipped MCP runtime behavior.
- The current feature state no longer relies on placeholder-only sections.
- No implemented `mcp:serve` command, runtime MCP server, or shipped MCP tool surface is being claimed by this feature state.
- The current feature state reflects the unimplemented, draft-planning-only status of `mcp-server`.

## Open Questions

- Which subset of Foundry read surfaces should become first-class MCP tools in the initial implementation?
- Whether the first implementation should use stdio only or support multiple transport modes remains unresolved.
- The exact rollout path from draft planning to active implementation is not yet decided.

## Next Steps

- Promote the draft execution spec when MCP work is ready to move from planning into active implementation.
- Decide the initial MCP transport and tool scope before implementing runtime behavior.
- Keep this feature state aligned with the repository until real MCP server code exists.
