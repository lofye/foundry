<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PlatformDefinitionCompilerTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        $this->createFeature('list_posts', 'GET', '/posts', ['posts.view']);
        $this->createFeature('view_post', 'GET', '/posts/{id}', ['posts.view']);
        $this->createFeature('create_post', 'POST', '/posts', ['posts.create']);
        $this->createFeature('update_post', 'PUT', '/posts/{id}', ['posts.update']);
        $this->createFeature('delete_post', 'DELETE', '/posts/{id}', ['posts.delete']);
        $this->createFeature('publish_post', 'POST', '/posts/{id}/publish', ['posts.publish'], ['post.published']);

        $this->createFeature('create_checkout_session', 'POST', '/billing/checkout', ['billing.manage']);
        $this->createFeature('view_billing_portal', 'GET', '/billing/portal', ['billing.manage']);
        $this->createFeature('handle_billing_webhook', 'POST', '/billing/webhook');
        $this->createFeature('list_invoices', 'GET', '/billing/invoices', ['billing.view']);
        $this->createFeature('view_current_subscription', 'GET', '/billing/subscription', ['billing.view']);

        $this->createFeature(
            'run_document_pipeline',
            'POST',
            '/documents/process',
            ['orchestration.start'],
            [],
            ['extract_document_text', 'generate_document_summary', 'classify_document', 'finalize_document_processing'],
        );

        mkdir($this->project->root . '/app/definitions/resources', 0777, true);
        mkdir($this->project->root . '/app/definitions/billing', 0777, true);
        mkdir($this->project->root . '/app/definitions/workflows', 0777, true);
        mkdir($this->project->root . '/app/definitions/orchestrations', 0777, true);
        mkdir($this->project->root . '/app/definitions/search', 0777, true);
        mkdir($this->project->root . '/app/definitions/streams', 0777, true);
        mkdir($this->project->root . '/app/definitions/locales', 0777, true);
        mkdir($this->project->root . '/app/definitions/roles', 0777, true);
        mkdir($this->project->root . '/app/definitions/policies', 0777, true);
        mkdir($this->project->root . '/app/definitions/inspect-ui', 0777, true);
        mkdir($this->project->root . '/lang/en', 0777, true);
        mkdir($this->project->root . '/lang/fr', 0777, true);

        file_put_contents($this->project->root . '/lang/en/messages.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'app.title' => 'Foundry',
    'auth.required' => 'Auth required.',
];
PHP);

        file_put_contents($this->project->root . '/lang/fr/messages.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'app.title' => 'Foundry FR',
    'auth.required' => 'Authentification requise.',
];
PHP);

        file_put_contents($this->project->root . '/app/definitions/resources/posts.resource.yaml', <<<'YAML'
version: 1
resource: posts
style: server-rendered
model:
  table: posts
  primary_key: id
fields:
  title:
    type: string
    required: true
features: [list, view, create, update, delete]
feature_names:
  list: list_posts
  view: view_post
  create: create_post
  update: update_post
  delete: delete_post
YAML);

        file_put_contents($this->project->root . '/app/definitions/billing/stripe.billing.yaml', <<<'YAML'
version: 1
provider: stripe
plans:
  - key: starter
    display_name: Starter
    price_id: price_starter
    interval: month
    trial_days: 14
  - key: pro
    display_name: Pro
    price_id: price_pro
    interval: month
    trial_days: 14
feature_names:
  checkout: create_checkout_session
  portal: view_billing_portal
  webhook: handle_billing_webhook
  invoices: list_invoices
  subscription: view_current_subscription
webhook_signing_secret_env: STRIPE_WEBHOOK_SECRET
YAML);

        file_put_contents($this->project->root . '/app/definitions/workflows/posts.workflow.yaml', <<<'YAML'
version: 1
resource: posts
states: [draft, review, published, archived]
transitions:
  publish:
    from: [review]
    to: published
    permission: posts.publish
    emit: [post.published]
  archive:
    from: [published]
    to: archived
YAML);

        file_put_contents($this->project->root . '/app/definitions/orchestrations/process_uploaded_document.orchestration.yaml', <<<'YAML'
version: 1
name: process_uploaded_document
steps:
  - name: extract_text
    job: extract_document_text
  - name: generate_summary
    job: generate_document_summary
    depends_on: [extract_text]
  - name: classify_document
    job: classify_document
    depends_on: [extract_text]
  - name: finalize
    job: finalize_document_processing
    depends_on: [generate_summary, classify_document]
YAML);

        file_put_contents($this->project->root . '/app/definitions/search/posts.search.yaml', <<<'YAML'
version: 1
index: posts
adapter: sql
resource: posts
source:
  table: posts
  primary_key: id
fields: [title, slug, body_markdown]
filters: [status, created_at]
YAML);

        file_put_contents($this->project->root . '/app/definitions/streams/job_progress.stream.yaml', <<<'YAML'
version: 1
stream: job_progress
transport: sse
route:
  path: /streams/job-progress
auth:
  required: true
  strategies: [session]
publish_features: [publish_post]
payload_schema:
  type: object
  additionalProperties: false
  properties:
    event: { type: string }
    data: { type: object }
YAML);

        file_put_contents($this->project->root . '/app/definitions/locales/core.locale.yaml', <<<'YAML'
version: 1
bundle: core
default: en
locales: [en, fr]
translation_paths: [lang]
YAML);

        file_put_contents($this->project->root . '/app/definitions/roles/default.roles.yaml', <<<'YAML'
version: 1
set: default
roles:
  admin:
    permissions: ['*']
  editor:
    permissions: [posts.publish, posts.update]
  viewer:
    permissions: [posts.view]
YAML);

        file_put_contents($this->project->root . '/app/definitions/policies/posts.policy.yaml', <<<'YAML'
version: 1
policy: posts
resource: posts
rules:
  admin: ['*']
  editor: [posts.publish, posts.update]
  viewer: [posts.view]
YAML);

        file_put_contents($this->project->root . '/app/definitions/inspect-ui/dev.inspect-ui.yaml', <<<'YAML'
version: 1
name: dev
enabled: true
base_path: /dev/inspect
require_auth: true
sections: [features, routes, schemas, auth, jobs, events, caches, contexts]
YAML);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_platform_definitions_compile_into_graph_and_projections(): void
    {
        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $this->assertNotNull($result->graph->node('billing:stripe'));
        $this->assertNotNull($result->graph->node('workflow:posts'));
        $this->assertNotNull($result->graph->node('orchestration:process_uploaded_document'));
        $this->assertNotNull($result->graph->node('search_index:posts'));
        $this->assertNotNull($result->graph->node('stream:job_progress'));
        $this->assertNotNull($result->graph->node('locale_bundle:core'));
        $this->assertNotNull($result->graph->node('role:admin'));
        $this->assertNotNull($result->graph->node('policy:posts'));
        $this->assertNotNull($result->graph->node('inspect_ui:dev'));

        $this->assertNotEmpty($result->graph->dependencies('billing:stripe'));
        $this->assertNotEmpty($result->graph->dependencies('workflow:posts'));
        $this->assertNotEmpty($result->graph->dependencies('stream:job_progress'));

        foreach ([
            'billing_index.php',
            'workflow_index.php',
            'orchestration_index.php',
            'search_index.php',
            'stream_index.php',
            'locale_index.php',
            'role_index.php',
            'policy_index.php',
            'inspect_ui_index.php',
        ] as $projection) {
            $this->assertFileExists($this->project->root . '/app/.foundry/build/projections/' . $projection);
        }

        $billing = require $this->project->root . '/app/.foundry/build/projections/billing_index.php';
        $roles = require $this->project->root . '/app/.foundry/build/projections/role_index.php';
        $policies = require $this->project->root . '/app/.foundry/build/projections/policy_index.php';

        $this->assertArrayHasKey('stripe', $billing);
        $this->assertArrayHasKey('admin', $roles);
        $this->assertArrayHasKey('posts', $policies);
        $this->assertLessThan(10, $result->diagnostics->summary()['error']);
    }

    /**
     * @param array<int,string> $permissions
     * @param array<int,string> $emitEvents
     * @param array<int,string> $dispatchJobs
     */
    private function createFeature(
        string $feature,
        string $method,
        string $path,
        array $permissions = [],
        array $emitEvents = [],
        array $dispatchJobs = [],
    ): void {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        $permissions = array_values(array_unique(array_map('strval', $permissions)));
        $emitEvents = array_values(array_unique(array_map('strval', $emitEvents)));
        $dispatchJobs = array_values(array_unique(array_map('strval', $dispatchJobs)));

        $permissionsYaml = $permissions === [] ? '[]' : '[' . implode(', ', $permissions) . ']';
        $emitYaml = $emitEvents === [] ? '[]' : '[' . implode(', ', $emitEvents) . ']';
        $jobsYaml = $dispatchJobs === [] ? '[]' : '[' . implode(', ', $dispatchJobs) . ']';

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 2
feature: {$feature}
kind: http
description: test
route:
  method: {$method}
  path: {$path}
input:
  schema: app/features/{$feature}/input.schema.json
output:
  schema: app/features/{$feature}/output.schema.json
auth:
  required: true
  strategies: [session]
  permissions: {$permissionsYaml}
database:
  reads: []
  writes: []
  transactions: optional
  queries: [q]
cache:
  reads: []
  writes: []
  invalidate: []
events:
  emit: {$emitYaml}
  subscribe: []
jobs:
  dispatch: {$jobsYaml}
rate_limit:
  strategy: user
  bucket: {$feature}
  cost: 1
tests:
  required: [contract]
llm:
  editable: true
  risk_level: low
YAML);

        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/queries.sql', "-- name: q\nSELECT 1;\n");
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: {$permissionsYaml}\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: {$emitYaml}\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: {$jobsYaml}\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
    }
}
