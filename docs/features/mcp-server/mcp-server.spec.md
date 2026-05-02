# Feature Spec: mcp-server

## Purpose

- Define the canonical feature context for Foundry's future MCP server surface.
- Preserve a deterministic planning contract for MCP work before runtime implementation begins.

## Goals

- Keep the feature documented under canonical spec, state, and decision files.
- Track MCP server work through feature-local execution specs.
- Keep current intent truthful while the feature remains unimplemented.

## Non-Goals

- Do not claim that an MCP server runtime already exists.
- Do not define mutation or write-capable MCP behavior in the current repository state.
- Do not bypass the normal feature-context workflow for future MCP work.

## Constraints

- Feature context must remain deterministic and machine-consumable.
- Any future MCP implementation must be driven by canonical feature context rather than draft specs alone.
- Current documentation must describe present repository reality accurately.

## Expected Behavior

- The repository contains canonical feature context files for `mcp-server`.
- MCP planning work may exist as draft execution specs under `docs/mcp-server/specs/drafts/`.
- The current repository state may include a draft read-layer execution spec at `docs/mcp-server/specs/drafts/001-read-layer.md`.
- The draft read-layer execution spec describes a future deterministic, read-only MCP server surface for Foundry introspection.
- The current feature state may explicitly report draft planning artifacts without claiming shipped MCP runtime behavior.
- The current feature state may explicitly report that it no longer relies on placeholder-only sections.
- Current `mcp-server` work is documentation and planning only until an active execution spec is promoted and implemented.
- No implemented MCP server command or runtime behavior is implied by this context alone.

## Acceptance Criteria

- `mcp-server` has canonical spec, state, and decisions documents in the feature-local docs layout.
- The current feature state may truthfully report draft planning artifacts without claiming shipped MCP runtime behavior.
- The current feature state can be read without placeholder-only sections.
- The current feature state reflects the unimplemented, draft-planning-only status of `mcp-server`.

## Assumptions

- MCP server implementation work will be added later through promoted execution specs.
- The existing draft execution spec describes future intended work rather than completed behavior.
