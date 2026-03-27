<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use Foundry\Verification\BillingVerifier;
use Foundry\Verification\LocalesVerifier;
use Foundry\Verification\OrchestrationsVerifier;
use Foundry\Verification\PoliciesVerifier;
use Foundry\Verification\SearchVerifier;
use Foundry\Verification\StreamsVerifier;
use Foundry\Verification\WorkflowVerifier;
use PHPUnit\Framework\TestCase;

final class PlatformVerifiersTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        $this->createFeature('publish_post', 'POST', '/posts/{id}/publish', ['posts.publish'], ['post.published']);
        $this->createFeature('stream_events', 'GET', '/streams/events', ['streams.view']);

        mkdir($this->project->root . '/app/definitions/billing', 0777, true);
        mkdir($this->project->root . '/app/definitions/workflows', 0777, true);
        mkdir($this->project->root . '/app/definitions/orchestrations', 0777, true);
        mkdir($this->project->root . '/app/definitions/search', 0777, true);
        mkdir($this->project->root . '/app/definitions/streams', 0777, true);
        mkdir($this->project->root . '/app/definitions/locales', 0777, true);
        mkdir($this->project->root . '/app/definitions/roles', 0777, true);
        mkdir($this->project->root . '/app/definitions/policies', 0777, true);
        mkdir($this->project->root . '/lang/en', 0777, true);
        mkdir($this->project->root . '/lang/fr', 0777, true);

        file_put_contents($this->project->root . '/lang/en/messages.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['greeting' => 'Hello', 'farewell' => 'Bye'];
PHP);

        file_put_contents($this->project->root . '/lang/fr/messages.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['greeting' => 'Bonjour', 'farewell' => 'Salut'];
PHP);

        file_put_contents($this->project->root . '/app/definitions/billing/stripe.billing.yaml', <<<'YAML'
version: 1
provider: stripe
plans:
  - key: starter
    price_id: price_starter
feature_names:
  checkout: publish_post
YAML);

        file_put_contents($this->project->root . '/app/definitions/workflows/posts.workflow.yaml', <<<'YAML'
version: 1
resource: posts
states: [draft, review, published]
transitions:
  publish:
    from: [review]
    to: published
    permission: posts.publish
    emit: [post.published]
YAML);

        file_put_contents($this->project->root . '/app/definitions/orchestrations/process.orchestration.yaml', <<<'YAML'
version: 1
name: process
steps:
  - name: extract
    job: extract_document_text
  - name: finalize
    job: finalize_document_processing
    depends_on: [extract]
YAML);

        file_put_contents($this->project->root . '/app/definitions/search/posts.search.yaml', <<<'YAML'
version: 1
index: posts
adapter: sql
fields: [title]
filters: [status]
YAML);

        file_put_contents($this->project->root . '/app/definitions/streams/events.stream.yaml', <<<'YAML'
version: 1
stream: events
transport: sse
route:
  path: /streams/events
auth:
  required: true
  strategies: [session]
publish_features: [stream_events]
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
    permissions: [posts.publish]
YAML);

        file_put_contents($this->project->root . '/app/definitions/policies/posts.policy.yaml', <<<'YAML'
version: 1
policy: posts
resource: posts
rules:
  admin: ['*']
  editor: [posts.publish]
YAML);

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_platform_verifiers_pass_for_valid_configuration(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);

        $this->assertTrue((new BillingVerifier($compiler))->verify()->ok);
        $this->assertTrue((new WorkflowVerifier($compiler))->verify()->ok);
        $this->assertTrue((new OrchestrationsVerifier($compiler))->verify()->ok);
        $this->assertTrue((new SearchVerifier($compiler))->verify()->ok);
        $this->assertTrue((new StreamsVerifier($compiler))->verify()->ok);
        $this->assertTrue((new LocalesVerifier($compiler, $paths))->verify()->ok);
        $this->assertTrue((new PoliciesVerifier($compiler))->verify()->ok);
    }

    public function test_platform_verifiers_report_missing_named_nodes(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);

        $this->assertContains(
            'Billing provider not found in compiled graph: missing',
            (new BillingVerifier($compiler))->verify('missing')->errors,
        );
        $this->assertContains(
            'Workflow not found in compiled graph: missing',
            (new WorkflowVerifier($compiler))->verify('missing')->errors,
        );
        $this->assertContains(
            'Orchestration not found in compiled graph: missing',
            (new OrchestrationsVerifier($compiler))->verify('missing')->errors,
        );
        $this->assertContains(
            'Search index not found in compiled graph: missing',
            (new SearchVerifier($compiler))->verify('missing')->errors,
        );
        $this->assertContains(
            'Stream not found in compiled graph: missing',
            (new StreamsVerifier($compiler))->verify('missing')->errors,
        );
        $this->assertContains(
            'Locale bundle not found in compiled graph: missing',
            (new LocalesVerifier($compiler, $paths))->verify('missing')->errors,
        );
        $this->assertContains(
            'Policy not found in compiled graph: missing',
            (new PoliciesVerifier($compiler))->verify('missing')->errors,
        );
    }

    public function test_platform_verifiers_report_invalid_payloads(): void
    {
        file_put_contents($this->project->root . '/app/definitions/billing/stripe.billing.yaml', <<<'YAML'
version: 1
provider: stripe
plans:
  - key: starter
    price_id: ""
YAML);
        file_put_contents($this->project->root . '/app/definitions/workflows/posts.workflow.yaml', <<<'YAML'
version: 1
resource: posts
states: [draft]
transitions:
  publish:
    from: [review]
    to: archived
YAML);
        file_put_contents($this->project->root . '/app/definitions/orchestrations/process.orchestration.yaml', <<<'YAML'
version: 1
name: process
steps:
  - name: extract
  - name: finalize
    job: finalize_document_processing
    depends_on: [missing]
YAML);
        file_put_contents($this->project->root . '/app/definitions/search/posts.search.yaml', <<<'YAML'
version: 1
index: posts
adapter: unsupported
fields: []
YAML);
        file_put_contents($this->project->root . '/app/definitions/streams/events.stream.yaml', <<<'YAML'
version: 1
stream: events
transport: websocket
route:
  path: ""
auth: {}
YAML);
        file_put_contents($this->project->root . '/app/definitions/locales/core.locale.yaml', <<<'YAML'
version: 1
bundle: core
default: en
locales: [fr]
translation_paths: [lang]
YAML);
        file_put_contents($this->project->root . '/app/definitions/roles/default.roles.yaml', <<<'YAML'
version: 1
set: default
roles:
  admin:
    permissions: ['*']
YAML);
        file_put_contents($this->project->root . '/app/definitions/policies/posts.policy.yaml', <<<'YAML'
version: 1
policy: posts
resource: posts
rules:
  ghost: []
YAML);

        $paths = Paths::fromCwd($this->project->root);
        $compiler = new GraphCompiler($paths);

        $billing = (new BillingVerifier($compiler))->verify();
        $this->assertFalse($billing->ok);
        $this->assertContains('Billing provider stripe plan starter is missing price_id.', $billing->errors);

        $workflow = (new WorkflowVerifier($compiler))->verify();
        $this->assertFalse($workflow->ok);
        $this->assertNotEmpty($workflow->errors);

        $orchestrations = (new OrchestrationsVerifier($compiler))->verify();
        $this->assertFalse($orchestrations->ok);
        $this->assertNotEmpty($orchestrations->errors);

        $search = (new SearchVerifier($compiler))->verify();
        $this->assertTrue($search->ok);
        $this->assertSame([], $search->errors);
        $this->assertNotEmpty($search->warnings);

        $streams = (new StreamsVerifier($compiler))->verify();
        $this->assertFalse($streams->ok);
        $this->assertNotEmpty($streams->errors);
        $this->assertNotEmpty($streams->warnings);

        $locales = (new LocalesVerifier($compiler, $paths))->verify();
        $this->assertFalse($locales->ok);
        $this->assertNotEmpty($locales->errors);

        $policies = (new PoliciesVerifier($compiler))->verify();
        $this->assertFalse($policies->ok);
        $this->assertNotEmpty($policies->errors);
        $this->assertNotEmpty($policies->warnings);
    }

    /**
     * @param array<int,string> $permissions
     * @param array<int,string> $emitEvents
     */
    private function createFeature(
        string $feature,
        string $method,
        string $path,
        array $permissions = [],
        array $emitEvents = [],
    ): void {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        $permissions = array_values(array_unique(array_map('strval', $permissions)));
        $permissionsYaml = $permissions === [] ? '[]' : '[' . implode(', ', $permissions) . ']';
        $emitEvents = array_values(array_unique(array_map('strval', $emitEvents)));
        $emitYaml = $emitEvents === [] ? '[]' : '[' . implode(', ', $emitEvents) . ']';

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
  dispatch: []
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
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
    }
}
