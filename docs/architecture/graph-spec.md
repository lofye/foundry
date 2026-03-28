The Foundry Graph Specification

Canonical Graph Contract

1. Purpose

The Foundry graph is a versioned contract, not an incidental compiler detail.

It defines:
- the canonical node and edge vocabularies
- the legal ways nodes may connect
- the integrity rules required before runtime
- the serialized JSON shape emitted for tooling, docs, and inspection

The graph remains the center of the Foundry compile model:

Spec -> Graph -> Compile -> Execute

2. Active Versions

The current canonical graph contract is:
- `graph_version = 2`
- `graph_spec_version = 1`

`graph_version` tracks the serialized graph artifact.
`graph_spec_version` tracks the formal node, edge, compatibility, and invariant rules.

3. Canonical Artifact

The canonical graph artifact is emitted at:

`app/.foundry/build/graph/app_graph.json`

The JSON contract includes:
- graph metadata
- `graph_metadata`
- `nodes`
- `edges`
- `summary`
- `integrity`
- `compatibility`
- `observability`

The emitted graph must be deterministic for identical inputs.

4. Node Registry

All nodes must use a registered canonical type name. Unknown node types are invalid and fail explicitly during deserialization and integrity verification.

The current canonical node types are:
- `feature`
- `route`
- `schema`
- `permission`
- `query`
- `event`
- `job`
- `cache`
- `scheduler`
- `webhook`
- `test`
- `context_manifest`
- `auth`
- `rate_limit`
- `pipeline_stage`
- `guard`
- `interceptor`
- `execution_plan`
- `starter_kit`
- `resource`
- `admin_resource`
- `upload_profile`
- `listing_config`
- `form_definition`
- `notification`
- `api_resource`
- `billing`
- `workflow`
- `orchestration`
- `search_index`
- `stream`
- `locale_bundle`
- `role`
- `policy`
- `inspect_ui`

Each registered node type declares:
- canonical type name
- backing class
- semantic category
- runtime scope
- required payload keys
- optional payload keys
- payload type rules
- execution-topology participation
- ownership-topology participation
- graph compatibility markers
- traceable/profileable observability flags

5. Semantic Categories

Node definitions are grouped into semantic categories so the graph can be reasoned about safely by tooling and agents.

Current categories include:
- `structural`
- `interface`
- `contract`
- `policy`
- `integration`
- `execution`
- `observational`
- `resource`

6. Edge Registry

All edges must use a registered canonical edge type. Unknown edge types are invalid and fail explicitly during integrity verification.

The current canonical edge types are:
- `feature_to_route`
- `feature_to_input_schema`
- `feature_to_output_schema`
- `feature_to_permission`
- `feature_to_query`
- `feature_to_event_emit`
- `feature_to_event_subscribe`
- `serves`
- `emits`
- `subscribes`
- `event_publisher_to_subscriber`
- `feature_to_job_dispatch`
- `feature_to_cache_invalidation`
- `feature_to_scheduler_task`
- `feature_to_webhook`
- `feature_to_test`
- `feature_to_context_manifest`
- `feature_to_auth_config`
- `feature_to_rate_limit`
- `feature_to_execution_plan`
- `execution_plan_to_feature_action`
- `route_to_execution_plan`
- `execution_plan_to_stage`
- `execution_plan_to_guard`
- `execution_plan_to_interceptor`
- `feature_to_guard`
- `guard_to_pipeline_stage`
- `pipeline_stage_next`
- `interceptor_to_pipeline_stage`
- `resource_to_feature`
- `resource_to_form_definition`
- `form_definition_to_feature`
- `resource_to_listing_config`
- `listing_config_to_feature`
- `admin_resource_to_resource`
- `admin_resource_to_feature`
- `upload_profile_to_feature`
- `starter_kit_to_feature`
- `notification_to_input_schema`
- `feature_to_notification_dispatch`
- `notification_to_feature`
- `api_resource_to_resource`
- `resource_to_api_resource`
- `api_resource_to_feature`
- `feature_to_api_resource`
- `billing_to_feature`
- `feature_to_billing`
- `feature_to_workflow`
- `workflow_to_permission`
- `workflow_to_event_emit`
- `orchestration_to_job`
- `search_index_to_resource`
- `resource_to_search_index`
- `feature_to_stream`
- `stream_to_feature`
- `role_to_permission`
- `policy_to_role`
- `policy_to_permission`

Each registered edge type declares:
- canonical type name
- semantic class
- legal source node types
- legal target node types
- multiplicity rules
- payload allowance and required payload keys
- payload type rules
- graph roles

7. Edge Roles

Edge roles are explicit and machine-readable. Current roles include:
- `dependency`
- `ownership`
- `execution`
- `invalidation`
- `publication`
- `observational`
- `structural`

Execution edges are distinct from ownership and dependency edges. This is required for runtime reasoning, pipeline verification, and future observability mapping.

8. Ownership Topology

Ownership is a first-class graph concern.

The canonical graph models ownership for relationships such as:
- feature -> route
- feature -> input schema
- feature -> output schema
- feature -> execution plan
- feature -> auth config
- feature -> rate limit
- feature -> tests
- feature -> context manifest
- feature -> resource and generated surface nodes where applicable

Ownership topology is used for:
- impact analysis
- targeted subgraph extraction
- safer bug localization
- multi-agent partitioning

9. Execution Topology

Execution semantics are explicit in the graph contract.

Execution topology includes:
- `execution_plan`
- `pipeline_stage`
- `guard`
- `interceptor`
- execution edges between routes, plans, stages, guards, and interceptors

Execution integrity must reject impossible flows, including orphan execution plans and illegal execution targets.

10. Observability Hooks

The graph carries stable observability-oriented semantics so runtime evidence can map back onto the canonical structure later.

Current graph definitions include:
- traceable node markers
- profileable node markers
- stable node ids
- stable edge ids
- graph and subgraph fingerprints

11. Integrity Rules

Graph integrity verification checks:

Node integrity:
- node ids are unique
- node types are recognized
- required payload keys are present
- payload values respect declared type rules
- graph compatibility includes the active graph version

Edge integrity:
- edge ids are unique
- edge types are recognized
- source and target nodes exist
- source and target type pairings are legal
- required payload keys are present when payload is used
- multiplicity rules are respected

Structural integrity:
- execution plans are not orphaned
- guards and interceptors are connected into execution topology
- route nodes have owning features
- execution edges do not terminate in impossible categories

Compatibility integrity:
- graph version is supported by the active specification
- graph spec version matches the canonical specification
- unknown node types are never silently coerced

12. Deserialization Rules

Graph deserialization is strict.

Unknown node types must not silently fall back to `feature`.
Unknown edge types must not be treated as legal.

Graph compatibility metadata is normalized during load, and incompatible artifacts fail deterministically.

13. Migration Rules

Graph version transitions must be explicit.

The current migration path supports deterministic upgrade from graph version 1 to graph version 2 by:
- adding `graph_spec_version`
- adding `graph_metadata`
- normalizing compatibility markers
- preserving stable node and edge ids

Artifacts that cannot be migrated safely must fail with explicit errors.

14. Inspection Surfaces

Foundry exposes machine-readable inspection surfaces for the graph contract:
- `php bin/foundry inspect graph-spec --json`
- `php bin/foundry inspect node-types --json`
- `php bin/foundry inspect edge-types --json`
- `php bin/foundry inspect subgraph <feature> --json`
- `php bin/foundry inspect graph-integrity --json`
- `php bin/foundry verify graph --json`
- `php bin/foundry verify graph-integrity --json`
- `php bin/foundry doctor --graph --json`

These surfaces are the supported way to inspect the live canonical contract in a compiled app.

15. Subgraphs and Fingerprints

Application graphs support extraction of:
- feature-scoped subgraphs
- ownership subgraphs
- execution subgraphs
- observability subgraphs

Stable fingerprints are available for:
- the entire graph
- topology
- payload structure

These fingerprints support deterministic comparison, regression tracking, and future temporal diffing.

16. Contract Discipline

The graph contract is versioned and must remain deterministic.

If behavior changes in a way that affects users or tooling:
1. update this specification
2. update the in-code graph specification
3. update tests
4. verify compile, inspect, verify, and doctor behavior

No graph behavior may drift silently.
