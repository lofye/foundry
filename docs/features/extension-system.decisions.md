### Decision: adopt a deterministic service-provider-based pack entry contract

Timestamp: 2026-04-27T00:00:00-04:00

**Context**

- Foundry needed a deterministic extension model that fits LLM-driven development and the graph-first compilation workflow.
- Installed packs needed a narrow registration surface instead of implicit filesystem discovery or mutable global registries.

**Decision**

- Use a `PackServiceProvider` contract with a restricted `PackContext` as the canonical installed-pack entrypoint.

**Reasoning**

- A service-provider contract is easy to inspect, test, and validate.
- A narrow `PackContext` keeps pack registration explicit and prevents hidden ambient mutation during activation.

**Alternatives Considered**

- Dynamic plugin loading.
- Global mutable registries with side-effect-driven registration.

**Consequences**

- Pack activation is explicit and deterministic.
- Pack capabilities must be declared through documented registration methods instead of hidden runtime behavior.

**Impact**

- The repository now has a stable installed-pack entry contract that aligns with compiler, explain, and inspection workflows.

**Spec Reference**

- Core Concepts
- Lifecycle
- Constraints
- Expected Behavior

### Decision: make ExtensionRegistry the canonical assembly boundary for built-in extensions, explicit registrations, and active installed packs

Timestamp: 2026-04-29T14:10:00-04:00

**Context**

- The implementation already loads extension state from three sources: built-in compiler extensions, explicit registration files, and active installed packs.
- Feature documentation needed one authoritative definition of where extension discovery starts and how deterministic load order is produced.

**Decision**

- Treat `ExtensionRegistry::forPaths()` as the only canonical extension assembly path.
- Keep supported registration sources limited to built-ins, `foundry.extensions.php`, `config/foundry/extensions.php`, and active entries from `.foundry/packs/installed.json`.

**Reasoning**

- One assembly boundary keeps compiler, generate, inspect, explain, and doctor surfaces aligned on the same extension state.
- Limiting discovery inputs prevents hidden extension activation and keeps load order reproducible.

**Alternatives Considered**

- Allow ad hoc extension scanning from arbitrary directories.
- Let each subsystem discover extension state independently.

**Consequences**

- Extension diagnostics, lifecycle state, compatibility, and load order are now defined centrally.
- Any new extension-registration source must be added deliberately to the registry contract instead of appearing implicitly.

**Impact**

- The extension system documentation can now describe one deterministic architecture instead of a collection of loosely related loaders.

**Spec Reference**

- Goals
- Architecture
- Lifecycle
- Constraints
- Expected Behavior

### Decision: execute extension-owned behavior only from enabled registry entries and keep disabled entries inspectable

Timestamp: 2026-04-29T14:16:00-04:00

**Context**

- The runtime already distinguishes between discovered extensions and enabled extensions after metadata validation, dependency checks, conflict resolution, and cycle detection.
- Documentation needed to clarify whether invalid or conflicting extensions still influence compile or generate behavior.

**Decision**

- Only enabled registry entries may contribute runtime integrations such as compiler passes, generators, migration rules, codemods, pipeline stages, and doctor checks.
- Disabled entries remain visible through inspect, explain, lifecycle, and diagnostic output.

**Reasoning**

- This preserves deterministic runtime behavior while keeping failure analysis transparent.
- Inspection must remain truthful even when an extension cannot run.

**Alternatives Considered**

- Drop disabled extensions completely from surfaced state.
- Allow partially invalid extensions to keep contributing selected runtime hooks.

**Consequences**

- Runtime behavior stays conservative and predictable.
- Tooling can still explain why an extension or pack was discovered but not enabled.

**Impact**

- Compiler, generate, inspect, explain, and doctor outputs can all rely on the same enabled-versus-disabled boundary.

**Spec Reference**

- Goals
- Lifecycle
- Integration Points
- Constraints
- Acceptance Criteria

### Decision: keep pack generators as the only pack-context contribution type with direct GenerateEngine execution semantics in the current contract

Timestamp: 2026-04-29T14:22:00-04:00

**Context**

- `PackContext` currently records commands, schemas, workflows, events, guards, generators, and docs metadata.
- The implementation already gives generators direct runtime meaning through `GeneratorRegistry::forExtensions()`, while the other contribution types are currently surfaced as declared metadata and conflict inputs.

**Decision**

- Document pack generators as the only `PackContext` contribution type that currently changes `GenerateEngine` execution behavior directly.
- Document the remaining contribution types as declared metadata unless and until a dedicated runtime registry is implemented for them.

**Reasoning**

- The current code gives generators concrete planning and execution behavior, and it does not do the same for the other contribution categories.
- The feature state document must reflect that boundary exactly to avoid overstating runtime behavior.

**Alternatives Considered**

- Describe every `PackContext` contribution type as fully executable today.
- Collapse the contribution list to only currently executed types and hide the declared metadata categories.

**Consequences**

- The state file can describe existing behavior truthfully without underselling the declared contribution contract.
- Future work can promote additional contribution types into executable registries without redefining generator semantics.

**Impact**

- GenerateEngine integration is now documented precisely, and pack-contribution expectations are clearer for extension authors and framework contributors.

**Spec Reference**

- Core Concepts
- Integration Points
- Expected Behavior
- Acceptance Criteria

### Decision: keep the extension-system spec broader than the currently executed pack-contribution subset while documenting the present runtime limits explicitly in state

Timestamp: 2026-04-29T14:31:00-04:00

**Context**

- The canonical spec defines the full extension-system contract, including the complete `PackContext` contribution vocabulary and the long-term extension boundaries.
- The current implementation gives direct runtime behavior to compiler extensions and pack generators, but several declared pack contribution categories are still metadata-only.
- The current implementation also stores manifest signatures without cryptographic verification and deactivates packs without deleting installed files.

**Decision**

- Keep the canonical spec as the production target for the full extension-system contract.
- Record the current metadata-only contribution categories, missing signature verification, and deactivate-only removal semantics as explicit state-level limitations until those contracts are finalized further.

**Reasoning**

- The spec needs to preserve the intended system contract instead of shrinking to only the smallest currently executed subset.
- The state file must still say exactly what the current runtime does today.

**Alternatives Considered**

- Rewrite the spec down to only today’s executed contribution types.
- Remove the current limitations from state and let contributors infer them from code.

**Consequences**

- Contributors can distinguish stable feature intent from current implementation boundaries without losing either.
- Context repair remains honest about the present limits while preserving a stronger canonical contract for future implementation work.

**Impact**

- The extension-system context can pass validation without collapsing the spec into a changelog of temporary implementation limits.

**Spec Reference**

- Goals
- Core Concepts
- Constraints
- Expected Behavior
- Assumptions
