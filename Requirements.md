I gave this to GPT-5.3-Codex @ Extra High

This is a brand new project. You have completely free reign to do whatever you want within this project. You are welcome to use the globally installed composer, any packagist packages, and any other php or other web-related features and functions you want. You do not need my permission to implement anything in this project. Please find the requirements (created entirely by ChatGPT) in Requirements.md. The only requirement of my own that I will add is that I would like you to aim for extremely high test coverage, because this will be a reusable framework. Ignore the folder `.idea` -- it was automatically created by my IDE. Please populate the README.md with information an LLM would need in order to be able to create web apps using this framework. We are using the MIT license so this will be open source and free to the world.

⸻

Master Prompt for Codex: Build Forge

Build a production-minded, reusable, LLM-first PHP 8.5+ web framework named Forge.

Forge is designed primarily for LLMs creating, inspecting, modifying, and maintaining application code, not for maximizing human developer ergonomics.

The framework must optimize for:
	•	explicit contracts
	•	deterministic code generation
	•	feature-locality
	•	inspectability
	•	minimal ambiguity
	•	small safe edit surfaces
	•	high runtime performance
	•	horizontal scalability
	•	strong observability
	•	mechanical verification
	•	extremely high automated test coverage

This is a reusable framework, not a one-off app. Treat correctness, stability, and test coverage as first-class requirements.

Top priorities

When tradeoffs arise, prioritize in this order:
	1.	correctness
	2.	explicitness
	3.	analyzability by LLMs
	4.	extremely high automated test coverage
	5.	deterministic generation
	6.	runtime performance
	7.	scalability
	8.	observability
	9.	human readability
	10.	developer convenience

Test coverage requirement

Aim for extremely high test coverage across the framework core, because Forge is intended to be reused as infrastructure.
Target:
	•	near-complete coverage of core framework services and registries
	•	exhaustive coverage of parsing, validation, generation, verification, and execution pipelines
	•	strong integration coverage across HTTP, schema validation, feature execution, DB queries, queue dispatch, events, cache, scheduler, and CLI
	•	regression tests for all bugs found during implementation
	•	meaningful tests, not fake coverage theater

Include:
	•	unit tests
	•	integration tests
	•	contract tests
	•	CLI tests
	•	generator tests
	•	verifier tests
	•	end-to-end example app tests

Prefer building code in a way that is naturally testable. Avoid architecture that makes coverage difficult.

Hard design rules
	1.	No runtime magic as a primary mechanism
Avoid or minimize:
	•	reflection-heavy hot paths
	•	implicit route model binding
	•	facades with hidden global state
	•	auto-discovered subscribers without a compiled index
	•	ORM-style magic properties and dynamic relationship methods
	•	hidden lifecycle side effects
	2.	Organize application code by feature
All app behavior must be centered on self-contained feature folders.
	3.	Every boundary must have a contract
Contracts are required for:
	•	request input
	•	response output
	•	job payloads
	•	event payloads
	•	config
	•	permissions
	•	cache entries
	•	DB query parameters/results where practical
	4.	Code generation is first-class
Framework generation is core architecture, not a side utility.
	5.	Generated code must remain readable
Stable, boring, explicit code is preferred over clever code.
	6.	Important metadata must be machine-readable
An LLM should inspect reality through framework tools, not guess.
	7.	Feature-local context must be small
A typical feature should be understandable from one folder plus generated indexes.
	8.	Prefer generated indexes over runtime scanning
Production runtime should not discover app structure by crawling directories.

Non-goals for v1

Do not spend time on:
	•	fancy admin UI
	•	visual builders
	•	full ORM
	•	plugin marketplace
	•	websocket stack
	•	elaborate template engine
	•	event-sourcing/CQRS ideology machine
	•	annotation/attribute-heavy architecture
	•	package-ecosystem concerns beyond clean extensibility points

Repository structure

Create this structure:

forge/
  composer.json
  phpunit.xml
  README.md
  /bin
    forge
  /src
    /Core
      Kernel.php
      App.php
      RuntimeMode.php
      Environment.php

    /Http
      HttpKernel.php
      RequestContext.php
      ResponseEmitter.php
      Route.php
      RouteCollection.php
      RouteMatcher.php

    /Feature
      FeatureDefinition.php
      FeatureRegistry.php
      FeatureContextManifest.php
      FeatureLoader.php
      FeatureExecutor.php
      FeatureAction.php
      FeatureServices.php

    /Schema
      Schema.php
      SchemaRegistry.php
      SchemaValidator.php
      JsonSchemaValidator.php
      ValidationResult.php
      ValidationError.php

    /Auth
      AuthContext.php
      Authenticator.php
      AuthorizationDecision.php
      AuthorizationEngine.php
      PermissionRegistry.php

    /DB
      DatabaseManager.php
      Connection.php
      TransactionManager.php
      QueryDefinition.php
      QueryRegistry.php
      QueryExecutor.php
      SqlFileLoader.php
      QueryTrace.php

    /Cache
      CacheStore.php
      CacheManager.php
      CacheDefinition.php
      CacheRegistry.php
      CacheKeyBuilder.php

    /Queue
      JobDefinition.php
      JobRegistry.php
      JobDispatcher.php
      QueueDriver.php
      SyncQueueDriver.php
      DatabaseQueueDriver.php
      RedisQueueDriver.php
      Worker.php
      RetryPolicy.php

    /Events
      EventDefinition.php
      EventRegistry.php
      EventDispatcher.php
      EventSubscriber.php

    /Scheduler
      ScheduledTaskDefinition.php
      Scheduler.php
      SchedulerRegistry.php

    /Storage
      StorageDriver.php
      LocalStorageDriver.php
      S3StorageDriver.php
      FileDescriptor.php

    /Webhook
      IncomingWebhookDefinition.php
      OutgoingWebhookDefinition.php
      WebhookRegistry.php
      WebhookSigner.php
      WebhookVerifier.php

    /AI
      AIProvider.php
      AIRequest.php
      AIResponse.php
      AIResultCache.php
      AITrace.php
      AIManager.php

    /Observability
      Logger.php
      StructuredLogger.php
      TraceContext.php
      TraceRecorder.php
      MetricsRecorder.php
      AuditRecorder.php

    /Config
      ConfigRepository.php
      ConfigSchemaRegistry.php
      EnvLoader.php

    /Testing
      ContractTestGenerator.php
      FeatureTestGenerator.php
      AuthTestGenerator.php
      JobTestGenerator.php

    /Generation
      FeatureGenerator.php
      SchemaGenerator.php
      QueryGenerator.php
      TestGenerator.php
      IndexGenerator.php
      MigrationGenerator.php
      ContextManifestGenerator.php

    /CLI
      Application.php
      Command.php
      /Commands
        InspectFeatureCommand.php
        InspectRouteCommand.php
        GenerateFeatureCommand.php
        GenerateIndexesCommand.php
        VerifyFeatureCommand.php
        VerifyContractsCommand.php
        ServeCommand.php
        QueueWorkCommand.php
        ScheduleRunCommand.php

    /Support
      Paths.php
      Json.php
      Yaml.php
      Arr.php
      Str.php
      Clock.php
      Uuid.php
      Result.php
      ForgeError.php

  /stubs
    /feature
      feature.yaml.stub
      action.php.stub
      input.schema.json.stub
      output.schema.json.stub
      queries.sql.stub
      context.manifest.json.stub
      contract_test.php.stub
      feature_test.php.stub
      auth_test.php.stub
    /generated
      routes.php.stub
      feature_index.php.stub
      schema_index.php.stub
      permission_index.php.stub
      event_index.php.stub
      job_index.php.stub
      cache_index.php.stub

  /tests
    /Unit
    /Integration
    /Fixtures

  /examples
    /blog-api
    /dashboard
    /ai-pipeline

Application structure for apps built with Forge

Applications built with Forge must use this structure:

app/
  /features
    /publish_post
      feature.yaml
      action.php
      input.schema.json
      output.schema.json
      permissions.yaml
      queries.sql
      cache.yaml
      events.yaml
      jobs.yaml
      prompts.md
      context.manifest.json
      /tests
        contract_test.php
        feature_test.php
        auth_test.php

  /generated
    routes.php
    feature_index.php
    schema_index.php
    permission_index.php
    event_index.php
    job_index.php
    cache_index.php
    scheduler_index.php
    webhook_index.php

  /platform
    /bootstrap
      app.php
      providers.php
    /config
      app.php
      database.php
      queue.php
      cache.php
      auth.php
      ai.php
      storage.php
    /migrations
    /sql
    /logs
    /tmp
    /public
      index.php

Rules:
	•	app/features/* is the source of truth for app behavior
	•	app/generated/* is generated by Forge and can be regenerated
	•	app/platform/* is low-level configuration/bootstrap only

Feature folder contract

Every feature folder must contain at least:
	•	feature.yaml
	•	action.php
	•	input.schema.json
	•	output.schema.json
	•	context.manifest.json
	•	tests/

Optional but strongly encouraged:
	•	queries.sql
	•	permissions.yaml
	•	cache.yaml
	•	events.yaml
	•	jobs.yaml
	•	prompts.md

Feature manifest format

Use YAML in feature.yaml.

Baseline shape:

version: 1

feature: publish_post
kind: http

description: Create a new post and optionally publish it immediately.

owners:
  - content

route:
  method: POST
  path: /posts

input:
  schema: input.schema.json

output:
  schema: output.schema.json

auth:
  required: true
  strategies:
    - session
    - bearer
  permissions:
    - posts.create

database:
  reads:
    - users
  writes:
    - posts
  transactions: required
  queries:
    - insert_post
    - find_user_by_id

cache:
  reads: []
  writes: []
  invalidate:
    - posts:list

events:
  emit:
    - post.created
  subscribe: []

jobs:
  dispatch:
    - notify_followers

rate_limit:
  strategy: user
  bucket: post_create
  cost: 1

observability:
  audit: true
  trace: true
  log_level: info

tests:
  required:
    - contract
    - feature
    - auth

llm:
  editable: true
  risk: medium
  notes_file: prompts.md

Validation rules:
	•	version required, integer
	•	feature required, snake_case string
	•	kind required, one of http, job, event_handler, scheduled, webhook_incoming, webhook_outgoing, ai_task
	•	description required
	•	route required if kind=http
	•	input.schema and output.schema required
	•	auth required for http
	•	sections for database, cache, events, jobs, tests, and llm required even if mostly empty

Schema format

Use JSON Schema draft 2020-12.

Example input.schema.json:

{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "publish_post_input",
  "type": "object",
  "additionalProperties": false,
  "required": ["title", "slug", "body_markdown"],
  "properties": {
    "title": {
      "type": "string",
      "minLength": 1,
      "maxLength": 200
    },
    "slug": {
      "type": "string",
      "pattern": "^[a-z0-9-]+$",
      "minLength": 1,
      "maxLength": 200
    },
    "body_markdown": {
      "type": "string",
      "minLength": 1
    },
    "publish_now": {
      "type": "boolean",
      "default": false
    },
    "publish_at": {
      "type": ["string", "null"],
      "format": "date-time"
    }
  }
}

Example output.schema.json:

{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "publish_post_output",
  "type": "object",
  "additionalProperties": false,
  "required": ["id", "title", "slug", "status", "created_at"],
  "properties": {
    "id": {
      "type": "string"
    },
    "title": {
      "type": "string"
    },
    "slug": {
      "type": "string"
    },
    "status": {
      "type": "string",
      "enum": ["draft", "published", "scheduled"]
    },
    "created_at": {
      "type": "string",
      "format": "date-time"
    }
  }
}

Framework requirements for schemas:
	•	compile and cache schemas
	•	validate request input
	•	validate feature output before response emission
	•	validate job and event payloads
	•	expose schema registry for inspection

Query definition format

Use one queries.sql file per feature with named queries:

-- name: find_user_by_id
SELECT id, email, role
FROM users
WHERE id = :id
LIMIT 1;

-- name: insert_post
INSERT INTO posts (
  id,
  author_id,
  title,
  slug,
  body_markdown,
  status,
  publish_at,
  created_at
) VALUES (
  :id,
  :author_id,
  :title,
  :slug,
  :body_markdown,
  :status,
  :publish_at,
  :created_at
);

Rules:
	•	each query must be named
	•	names must be unique within file
	•	placeholders must be named
	•	Forge parses file into a query registry
	•	queries referenced in feature.yaml must be verified
	•	query timing must be traced
	•	query signatures must be inspectable

Permissions format

Use permissions.yaml:

version: 1

permissions:
  - posts.create
  - posts.publish

rules:
  posts.create:
    description: User can create posts.
  posts.publish:
    description: User can publish posts immediately.

Cache definition format

Use cache.yaml:

version: 1

entries:
  - key: posts:list
    kind: computed
    ttl_seconds: 300
    invalidated_by:
      - publish_post
      - update_post
      - delete_post

  - key: post:by_slug:{slug}
    kind: query
    ttl_seconds: 300
    invalidated_by:
      - publish_post
      - update_post
      - delete_post

Requirements:
	•	key placeholders supported
	•	invalidation graph inspectable
	•	compiled cache registry generated centrally

Event definition format

Use events.yaml:

version: 1

emit:
  - name: post.created
    schema:
      type: object
      additionalProperties: false
      required: [post_id, author_id, status]
      properties:
        post_id:
          type: string
        author_id:
          type: string
        status:
          type: string

subscribe: []

Requirements:
	•	every emitted event must declare schema
	•	subscriber graph must be centrally indexed
	•	invisible subscriber discovery is forbidden in v1

Job definition format

Use jobs.yaml:

version: 1

dispatch:
  - name: notify_followers
    input_schema:
      type: object
      additionalProperties: false
      required: [post_id]
      properties:
        post_id:
          type: string
    queue: default
    retry:
      max_attempts: 5
      backoff_seconds: [5, 30, 120, 300, 600]
    timeout_seconds: 60
    idempotency_key: post_id

Requirements:
	•	every job must declare payload schema
	•	retry policy required
	•	queue name required
	•	timeout required
	•	warn if idempotency key absent

Context manifest format

Generate context.manifest.json automatically.

Example shape:

{
  "version": 1,
  "feature": "publish_post",
  "kind": "http",
  "relevant_files": [
    "app/features/publish_post/feature.yaml",
    "app/features/publish_post/action.php",
    "app/features/publish_post/input.schema.json",
    "app/features/publish_post/output.schema.json",
    "app/features/publish_post/queries.sql",
    "app/features/publish_post/permissions.yaml",
    "app/features/publish_post/cache.yaml",
    "app/features/publish_post/events.yaml",
    "app/features/publish_post/jobs.yaml",
    "app/features/publish_post/tests/contract_test.php",
    "app/features/publish_post/tests/feature_test.php",
    "app/features/publish_post/tests/auth_test.php"
  ],
  "generated_files": [
    "app/generated/routes.php",
    "app/generated/schema_index.php",
    "app/generated/feature_index.php",
    "app/generated/permission_index.php",
    "app/generated/event_index.php",
    "app/generated/job_index.php",
    "app/generated/cache_index.php"
  ],
  "upstream_dependencies": [
    "auth",
    "db",
    "queue",
    "events"
  ],
  "downstream_dependents": [
    "notify_followers"
  ],
  "contracts": {
    "input": "app/features/publish_post/input.schema.json",
    "output": "app/features/publish_post/output.schema.json"
  },
  "tests": [
    "publish_post_contract_test",
    "publish_post_feature_test",
    "publish_post_auth_test"
  ],
  "forbidden_paths": [
    "src/Core",
    "src/Http"
  ],
  "risk_level": "medium"
}

Action contract

Define interface:

<?php
declare(strict_types=1);

namespace Forge\Feature;

use Forge\Http\RequestContext;
use Forge\Auth\AuthContext;

interface FeatureAction
{
    /**
     * @param array<string, mixed> $input Validated input matching input schema
     * @return array<string, mixed> Output matching output schema
     */
    public function handle(
        array $input,
        RequestContext $request,
        AuthContext $auth,
        FeatureServices $services
    ): array;
}

Define FeatureServices interface:

<?php
declare(strict_types=1);

namespace Forge\Feature;

use Forge\DB\QueryExecutor;
use Forge\Cache\CacheManager;
use Forge\Queue\JobDispatcher;
use Forge\Events\EventDispatcher;
use Forge\Storage\StorageDriver;
use Forge\Observability\TraceContext;
use Forge\AI\AIManager;

interface FeatureServices
{
    public function db(): QueryExecutor;
    public function cache(): CacheManager;
    public function jobs(): JobDispatcher;
    public function events(): EventDispatcher;
    public function storage(): StorageDriver;
    public function trace(): TraceContext;
    public function ai(): AIManager;
}

Rules:
	•	no magic injection beyond this contract
	•	all feature actions run through a consistent execution pipeline
	•	output is validated before response emission

HTTP execution pipeline

Implement exact request flow for kind=http features:
	1.	incoming request accepted
	2.	route matched from generated route index
	3.	feature definition loaded from generated feature index
	4.	auth strategy resolved
	5.	authorization checked
	6.	rate limit checked
	7.	input parsed and validated against input schema
	8.	transaction opened if required
	9.	action executed
	10.	declared side effects recorded and traced
	11.	output validated against output schema
	12.	transaction committed or rolled back
	13.	response serialized
	14.	trace, log, and audit emitted

This pipeline must be centralized and inspectable.

Core interfaces to implement

Implement at least these interfaces/classes early:

FeatureDefinition

<?php
declare(strict_types=1);

namespace Forge\Feature;

final class FeatureDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $description,
        public readonly ?array $route,
        public readonly string $inputSchemaPath,
        public readonly string $outputSchemaPath,
        public readonly array $auth,
        public readonly array $database,
        public readonly array $cache,
        public readonly array $events,
        public readonly array $jobs,
        public readonly array $rateLimit,
        public readonly array $tests,
        public readonly array $llm,
        public readonly string $basePath,
    ) {}
}

FeatureRegistry

<?php
declare(strict_types=1);

namespace Forge\Feature;

interface FeatureRegistry
{
    public function all(): array;
    public function has(string $feature): bool;
    public function get(string $feature): FeatureDefinition;
}

SchemaValidator

<?php
declare(strict_types=1);

namespace Forge\Schema;

interface SchemaValidator
{
    /**
     * @param array<string,mixed> $data
     */
    public function validate(array $data, string $schemaPath): ValidationResult;
}

QueryExecutor

<?php
declare(strict_types=1);

namespace Forge\DB;

interface QueryExecutor
{
    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function select(string $feature, string $queryName, array $params = []): array;

    /**
     * @param array<string,mixed> $params
     */
    public function execute(string $feature, string $queryName, array $params = []): int;
}

JobDispatcher

<?php
declare(strict_types=1);

namespace Forge\Queue;

interface JobDispatcher
{
    /**
     * @param array<string,mixed> $payload
     */
    public function dispatch(string $jobName, array $payload): void;
}

EventDispatcher

<?php
declare(strict_types=1);

namespace Forge\Events;

interface EventDispatcher
{
    /**
     * @param array<string,mixed> $payload
     */
    public function emit(string $eventName, array $payload): void;
}

CacheStore

<?php
declare(strict_types=1);

namespace Forge\Cache;

interface CacheStore
{
    public function get(string $key): mixed;
    public function put(string $key, mixed $value, int $ttlSeconds): void;
    public function forget(string $key): void;
    public function has(string $key): bool;
}

AIProvider

<?php
declare(strict_types=1);

namespace Forge\AI;

interface AIProvider
{
    public function name(): string;

    public function complete(AIRequest $request): AIResponse;
}

Generated indexes

Generate and maintain:
	•	app/generated/routes.php
	•	app/generated/feature_index.php
	•	app/generated/schema_index.php
	•	app/generated/permission_index.php
	•	app/generated/event_index.php
	•	app/generated/job_index.php
	•	app/generated/cache_index.php
	•	app/generated/scheduler_index.php
	•	app/generated/webhook_index.php

These should be plain PHP arrays or lightweight PHP classes for performance.

Example routes.php:

<?php
declare(strict_types=1);

return [
    'POST /posts' => [
        'feature' => 'publish_post',
        'kind' => 'http',
        'input_schema' => 'app/features/publish_post/input.schema.json',
        'output_schema' => 'app/features/publish_post/output.schema.json',
    ],
];

Do not use runtime folder scanning on hot paths.

CLI surface

Build bin/forge.

Implement these commands:

Inspection

forge inspect feature publish_post
forge inspect route POST /posts
forge inspect auth publish_post
forge inspect cache publish_post
forge inspect events publish_post
forge inspect jobs publish_post
forge inspect context publish_post
forge inspect dependencies publish_post

Generation

forge generate feature specs/publish_post.yaml
forge generate indexes
forge generate tests publish_post
forge generate migration specs/add_posts_table.yaml
forge generate context publish_post

Verification

forge verify feature publish_post
forge verify contracts
forge verify auth
forge verify cache
forge verify events
forge verify jobs
forge verify migrations

Runtime

forge serve
forge queue:work
forge queue:inspect
forge schedule:run
forge trace:tail

Planning / impact analysis

forge affected-files publish_post
forge impacted-features posts.create
forge impacted-features event:post.created
forge impacted-features cache:posts:list

CLI requirements:
	•	support --json output on all inspect, verify, and planning commands
	•	output must be stable and machine-readable
	•	JSON-mode errors must be structured

Inspect output shape

For forge inspect feature publish_post --json, use a shape like:

{
  "feature": "publish_post",
  "kind": "http",
  "description": "Create a new post and optionally publish it immediately.",
  "route": {
    "method": "POST",
    "path": "/posts"
  },
  "schemas": {
    "input": "app/features/publish_post/input.schema.json",
    "output": "app/features/publish_post/output.schema.json"
  },
  "auth": {
    "required": true,
    "strategies": ["session", "bearer"],
    "permissions": ["posts.create"]
  },
  "database": {
    "reads": ["users"],
    "writes": ["posts"],
    "queries": ["insert_post", "find_user_by_id"],
    "transactions": "required"
  },
  "cache": {
    "reads": [],
    "writes": [],
    "invalidate": ["posts:list"]
  },
  "events": {
    "emit": ["post.created"],
    "subscribe": []
  },
  "jobs": {
    "dispatch": ["notify_followers"]
  },
  "tests": ["contract", "feature", "auth"],
  "context_manifest": "app/features/publish_post/context.manifest.json",
  "relevant_files": [
    "app/features/publish_post/feature.yaml",
    "app/features/publish_post/action.php",
    "app/features/publish_post/input.schema.json",
    "app/features/publish_post/output.schema.json",
    "app/features/publish_post/queries.sql"
  ]
}

Verification rules

Implement these verifiers:

verify feature <name>

Check:
	•	required files exist
	•	YAML valid
	•	schemas valid JSON Schema
	•	queries referenced exist
	•	route valid
	•	permissions referenced exist
	•	event/job/cache references valid
	•	tests required by manifest exist

verify contracts

Check:
	•	all schemas parse
	•	job/event schemas valid
	•	no contract drift in generated indexes

verify auth

Check:
	•	every HTTP feature declares auth
	•	every referenced permission exists
	•	no unguarded write feature unless explicitly marked public

verify cache

Check:
	•	cache keys valid
	•	invalidation references resolvable
	•	no orphan invalidation targets

verify events

Check:
	•	emitted events have schema
	•	subscribers reference known events
	•	circular event chains warn

verify jobs

Check:
	•	payload schema exists
	•	retry config valid
	•	timeout valid
	•	queue name valid

verify migrations

Check:
	•	migration order valid
	•	dangerous operations flagged

Code generation rules
	1.	Generation must be deterministic
Same input spec and same framework version must produce same output.
	2.	Generated files must have clear headers
Example:

<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT DIRECTLY
 * Source: app/features/publish_post/feature.yaml
 * Regenerate with: forge generate indexes
 */

	3.	Keep generated code flat
Avoid unnecessary factories, managers, builders, orchestrators, and mystery scaffolding.
	4.	Test names must be predictable
Examples:
	•	publish_post_contract_test.php
	•	publish_post_feature_test.php
	•	publish_post_auth_test.php

Feature generation input spec

Support feature generation from YAML like:

version: 1

feature: publish_post
kind: http
description: Create a new post and optionally publish it immediately.

route:
  method: POST
  path: /posts

input:
  fields:
    title:
      type: string
      required: true
      minLength: 1
      maxLength: 200

    slug:
      type: string
      required: true
      pattern: "^[a-z0-9-]+$"

    body_markdown:
      type: string
      required: true

    publish_now:
      type: boolean
      required: false
      default: false

output:
  fields:
    id:
      type: string
      required: true
    title:
      type: string
      required: true
    slug:
      type: string
      required: true
    status:
      type: string
      required: true
      enum: [draft, published, scheduled]
    created_at:
      type: string
      required: true
      format: date-time

auth:
  required: true
  strategies: [session, bearer]
  permissions: [posts.create]

database:
  reads: [users]
  writes: [posts]
  queries:
    - find_user_by_id
    - insert_post
  transactions: required

cache:
  invalidate:
    - posts:list

events:
  emit:
    - post.created

jobs:
  dispatch:
    - notify_followers

tests:
  required:
    - contract
    - feature
    - auth

forge generate feature specs/publish_post.yaml must generate:
	•	feature folder
	•	schemas
	•	action stub
	•	query stub
	•	tests
	•	context manifest

Action stub template

Generated action.php should resemble:

<?php
declare(strict_types=1);

namespace App\Features\PublishPost;

use Forge\Feature\FeatureAction;
use Forge\Feature\FeatureServices;
use Forge\Http\RequestContext;
use Forge\Auth\AuthContext;

final class Action implements FeatureAction
{
    public function handle(
        array $input,
        RequestContext $request,
        AuthContext $auth,
        FeatureServices $services
    ): array {
        $userId = $auth->userId();

        $rows = $services->db()->select('publish_post', 'find_user_by_id', [
            'id' => $userId,
        ]);

        if ($rows === []) {
            throw new \RuntimeException('Authenticated user not found.');
        }

        $status = 'draft';
        if (($input['publish_now'] ?? false) === true) {
            $status = 'published';
        }

        $postId = bin2hex(random_bytes(16));
        $createdAt = gmdate('c');

        $services->db()->execute('publish_post', 'insert_post', [
            'id' => $postId,
            'author_id' => $userId,
            'title' => $input['title'],
            'slug' => $input['slug'],
            'body_markdown' => $input['body_markdown'],
            'status' => $status,
            'publish_at' => $input['publish_at'] ?? null,
            'created_at' => $createdAt,
        ]);

        $services->events()->emit('post.created', [
            'post_id' => $postId,
            'author_id' => $userId,
            'status' => $status,
        ]);

        $services->jobs()->dispatch('notify_followers', [
            'post_id' => $postId,
        ]);

        return [
            'id' => $postId,
            'title' => $input['title'],
            'slug' => $input['slug'],
            'status' => $status,
            'created_at' => $createdAt,
        ];
    }
}

Prefer this style: boring, explicit, traceable.

Error model

Implement structured framework errors.

Create base shape like:

<?php
declare(strict_types=1);

namespace Forge\Support;

final class ForgeError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $category,
        public readonly array $details = [],
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

HTTP JSON errors should look like:

{
  "error": {
    "code": "FEATURE_INPUT_SCHEMA_VIOLATION",
    "category": "validation",
    "message": "Input does not match schema.",
    "details": {
      "feature": "publish_post",
      "field": "slug"
    }
  }
}

CLI JSON-mode errors should use a similar structure.

Observability requirements

Implement structured tracing around:
	•	request start/end
	•	route match
	•	auth decision
	•	rate-limit decision
	•	schema validation
	•	query execution
	•	cache hit/miss
	•	job dispatch
	•	event emit
	•	AI call
	•	response emit

Each trace event should include:
	•	timestamp
	•	trace id
	•	span id or operation id
	•	feature
	•	component
	•	action
	•	duration where relevant
	•	metadata

AI support requirements

Build native AI support, but keep it explicit.

AIRequest

<?php
declare(strict_types=1);

namespace Forge\AI;

final class AIRequest
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $prompt,
        public readonly array $input = [],
        public readonly ?array $responseSchema = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly bool $cacheable = false,
        public readonly ?int $cacheTtlSeconds = null,
        public readonly array $metadata = [],
    ) {}
}

AIResponse

<?php
declare(strict_types=1);

namespace Forge\AI;

final class AIResponse
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $content,
        public readonly array $parsed = [],
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $costEstimate = 0.0,
        public readonly bool $cacheHit = false,
        public readonly array $metadata = [],
    ) {}
}

Requirements:
	•	AI calls traced
	•	token/cost metadata recorded
	•	optional response schema validation
	•	cache support for deterministic AI tasks
	•	queue integration for long AI operations

Performance constraints

Engineer for low overhead:
	•	precompiled route index
	•	precompiled feature index
	•	no scanning feature folders on hot request path
	•	minimal reflection in request path
	•	minimal container complexity
	•	explicit SQL instead of magical ORM in v1
	•	support PHP-FPM and persistent workers
	•	no request-state leakage in persistent mode

Prefer plain arrays and final classes over abstraction towers.

Testing strategy

Generate and support:
	•	unit tests
	•	integration tests
	•	contract tests
	•	CLI tests
	•	generator tests
	•	verifier tests
	•	end-to-end example tests

Also generate and support these feature-level tests:
	•	contract test
	•	feature test
	•	auth test
	•	job test where applicable

Generated contract test stub example:

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublishPostContractTest extends TestCase
{
    public function test_input_schema_accepts_valid_payload(): void
    {
        $this->assertTrue(true);
    }

    public function test_output_schema_matches_action_result_shape(): void
    {
        $this->assertTrue(true);
    }
}

But do not stop at stubs. Fill the framework core with strong real tests.

Test implementation instructions
	•	Build tests alongside framework code, not as an afterthought
	•	Every parser, registry, generator, verifier, and execution stage should have direct tests
	•	Every CLI command should have happy-path and failure-path tests
	•	Every generated artifact should have snapshot-like or structural assertions
	•	Example apps should have end-to-end integration coverage
	•	Add regression tests whenever a bug or ambiguity is discovered
	•	Track coverage and keep improving it as the framework grows

Milestone order

Milestone 1

Create:
	•	project skeleton
	•	CLI bootstrap
	•	feature manifest parser
	•	JSON schema validation
	•	route index generation
	•	feature index generation
	•	simple HTTP kernel
	•	feature action contract
	•	forge inspect feature
	•	forge generate feature
	•	forge generate indexes
	•	forge verify feature

Milestone 2

Create:
	•	DB manager
	•	SQL named-query parsing
	•	query registry
	•	transactions
	•	auth abstractions
	•	rate limit abstraction
	•	feature execution pipeline
	•	contract validation in runtime

Milestone 3

Create:
	•	cache system
	•	queue system
	•	event system
	•	scheduler
	•	storage abstractions

Milestone 4

Create:
	•	context manifest generation
	•	dependency/impact inspection
	•	structured tracing
	•	audit logging
	•	test generation
	•	verify auth/cache/events/jobs/contracts

Milestone 5

Create:
	•	AI provider abstraction
	•	AI tracing and caching
	•	queued AI task patterns
	•	examples

Required example apps

Include three examples.

Example 1: blog-api

Features:
	•	list_posts
	•	view_post
	•	publish_post
	•	update_post
	•	delete_post

Example 2: dashboard

Features:
	•	login
	•	current_user
	•	list_notifications
	•	upload_avatar

Example 3: ai-pipeline

Features:
	•	submit_document
	•	extract_summary
	•	classify_document
	•	queue_ai_summary_job
	•	fetch_ai_result

Human vs generated code boundaries
	•	framework core in src/ is hand-authored and stable
	•	app features in app/features/ may be generated and then edited
	•	indexes in app/generated/ are always generated
	•	generated files must not be silently overwritten unless marked generated
	•	if mixed-mode files are required, use explicit protected regions

Prefer avoiding mixed-mode in v1.

Coding style rules

Use:
	•	declare(strict_types=1);
	•	final classes by default
	•	explicit constructor injection
	•	no unnecessary traits
	•	no inheritance when composition works
	•	no hidden globals except tightly controlled immutable roots
	•	clear public API names

Naming:
	•	classes: PascalCase
	•	feature folders: snake_case
	•	feature namespaces: App\Features\PublishPost
	•	query names: snake_case
	•	event names: dotted strings like post.created

Additional instruction on architecture choices

When uncertain between:
	•	human-friendly abstraction vs machine-friendly explicit structure → choose machine-friendly explicit structure
	•	cleverness vs inspectability → choose inspectability
	•	runtime magic vs generated indexes → choose generated indexes
	•	ORM convenience vs explicit SQL in v1 → choose explicit SQL
	•	thinner tests vs stronger reusable-framework safety → choose stronger reusable-framework safety

Deliverables

Produce:
	1.	full Forge framework codebase
	2.	architecture document
	3.	feature definition spec
	4.	CLI implementation
	5.	generated stubs
	6.	strong automated tests with extremely high coverage
	7.	three example apps
	8.	benchmark notes for hot JSON endpoints
	9.	docs explaining how an LLM should inspect, generate, modify, and verify features safely

Final instruction

Build Forge as a disciplined, explicit, reusable factory for reliable web features.

Do not substitute a more “elegant” architecture if it reduces explicitness, analyzability, testability, or deterministic behavior.

Favor boring, explicit, high-signal systems over charming magic.

The goal is not to impress a human framework artisan.
The goal is to let LLMs work safely, correctly, and fast without wandering into a marsh of hidden behavior.
