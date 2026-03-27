<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\IR\NotificationNode;
use Foundry\Notifications\NotificationPreviewer;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class NotificationPreviewerTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        mkdir($this->project->root . '/app/definitions/notifications', 0777, true);
        mkdir($this->project->root . '/app/notifications/templates', 0777, true);
        mkdir($this->project->root . '/app/notifications/schemas', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_preview_requires_notification_name(): void
    {
        $previewer = $this->previewer();

        try {
            $previewer->preview('   ');
            self::fail('Expected notification required error.');
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_REQUIRED', $error->errorCode);
        }
    }

    public function test_preview_throws_when_notification_does_not_exist(): void
    {
        $this->compiler()->compile(new CompileOptions());
        $previewer = $this->previewer();

        try {
            $previewer->preview('missing_notification');
            self::fail('Expected notification not found error.');
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_NOT_FOUND', $error->errorCode);
        }
    }

    public function test_preview_renders_using_inline_required_schema(): void
    {
        $this->createFeature('dispatch_welcome_email', 'POST', '/dispatch/welcome');

        file_put_contents($this->project->root . '/app/notifications/templates/welcome_email.mail.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'subject' => 'Welcome {{user_id}} ({{status}})',
    'text' => 'enabled={{enabled}};attempts={{attempts}};score={{score}};tags={{tags}};profile={{profile}}',
    'html' => '<p>{{user_id}}</p>',
];
PHP);

        file_put_contents($this->project->root . '/app/definitions/notifications/welcome_email.notification.yaml', <<<'YAML'
version: 1
notification: welcome_email
channel: mail
queue: default
template: welcome_email
input_schema:
  type: object
  additionalProperties: false
  required: [user_id, enabled, attempts, score, tags, profile, status]
  properties:
    user_id:
      type: string
    enabled:
      type: boolean
    attempts:
      type: integer
    score:
      type: number
    tags:
      type: array
    profile:
      type: object
    status:
      type: string
      enum: [queued, delivered]
dispatch_features: [dispatch_welcome_email]
YAML);

        $this->compiler()->compile(new CompileOptions());

        $preview = $this->previewer()->preview('welcome_email');

        $this->assertSame('welcome_email', $preview['notification']);
        $this->assertSame('mail', $preview['channel']);
        $this->assertSame('default', $preview['queue']);
        $this->assertSame(
            [
                'attempts' => 1,
                'enabled' => true,
                'profile' => [],
                'score' => 1.0,
                'status' => 'queued',
                'tags' => [],
                'user_id' => 'sample_user_id',
            ],
            $preview['sample_input'],
        );
        $this->assertSame('Welcome sample_user_id (queued)', $preview['rendered']['subject']);
        $this->assertStringContainsString('enabled=true', $preview['rendered']['text']);
        $this->assertStringContainsString('attempts=1', $preview['rendered']['text']);
    }

    public function test_preview_loads_schema_file_and_limits_optional_samples_to_three_fields(): void
    {
        $this->createFeature('dispatch_digest_email', 'POST', '/dispatch/digest');

        file_put_contents($this->project->root . '/app/notifications/templates/weekly_digest.mail.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'subject' => 'Digest',
    'text' => 'ok',
    'html' => '',
];
PHP);

        file_put_contents($this->project->root . '/app/notifications/schemas/weekly_digest.input.schema.json', <<<'JSON'
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "zeta": { "type": "string" },
    "alpha": { "type": "integer" },
    "beta": { "type": "boolean" },
    "gamma": { "type": "number" }
  }
}
JSON);

        file_put_contents($this->project->root . '/app/definitions/notifications/weekly_digest.notification.yaml', <<<'YAML'
version: 1
notification: weekly_digest
channel: mail
queue: default
template: weekly_digest
input_schema: app/notifications/schemas/weekly_digest.input.schema.json
dispatch_features: [dispatch_digest_email]
YAML);

        $this->compiler()->compile(new CompileOptions());
        $preview = $this->previewer()->preview('weekly_digest');

        $this->assertCount(3, $preview['sample_input']);
        $this->assertSame(['alpha', 'beta', 'zeta'], array_keys($preview['sample_input']));
    }

    public function test_preview_uses_empty_input_when_schema_file_is_missing_or_invalid(): void
    {
        $this->createFeature('dispatch_report_email', 'POST', '/dispatch/report');
        file_put_contents($this->project->root . '/app/notifications/templates/report_email.mail.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['subject' => 'Report', 'text' => 'ok', 'html' => ''];
PHP);

        file_put_contents($this->project->root . '/app/notifications/schemas/broken.input.schema.json', '{invalid json');

        file_put_contents($this->project->root . '/app/definitions/notifications/broken.notification.yaml', <<<'YAML'
version: 1
notification: broken
channel: mail
queue: default
template: report_email
input_schema: app/notifications/schemas/broken.input.schema.json
dispatch_features: [dispatch_report_email]
YAML);

        file_put_contents($this->project->root . '/app/definitions/notifications/missing.notification.yaml', <<<'YAML'
version: 1
notification: missing_schema
channel: mail
queue: default
template: report_email
input_schema: app/notifications/schemas/missing.input.schema.json
dispatch_features: [dispatch_report_email]
YAML);

        $this->compiler()->compile(new CompileOptions());

        $brokenPreview = $this->previewer()->preview('broken');
        $missingPreview = $this->previewer()->preview('missing_schema');

        $this->assertSame([], $brokenPreview['sample_input']);
        $this->assertSame([], $missingPreview['sample_input']);
    }

    public function test_preview_throws_when_template_path_is_not_configured(): void
    {
        $graph = new ApplicationGraph(1, '0.1.0', '2026-03-09T00:00:00+00:00', 'hash');
        $graph->addNode(new NotificationNode(
            'notification:broken_template',
            'app/definitions/notifications/broken_template.notification.yaml',
            [
                'notification' => 'broken_template',
                'channel' => 'mail',
                'queue' => 'default',
                'template_path' => '',
                'input_schema' => [],
                'input_schema_path' => '',
            ],
        ));

        $path = $this->project->root . '/app/.foundry/build/graph';
        mkdir($path, 0777, true);
        file_put_contents(
            $path . '/app_graph.json',
            json_encode($graph->toArray(new DiagnosticBag()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        try {
            $this->previewer()->preview('broken_template');
            self::fail('Expected template path not configured error.');
        } catch (FoundryError $error) {
            $this->assertSame('NOTIFICATION_TEMPLATE_NOT_CONFIGURED', $error->errorCode);
        }
    }

    private function previewer(): NotificationPreviewer
    {
        $paths = Paths::fromCwd($this->project->root);

        return new NotificationPreviewer($paths, new GraphCompiler($paths));
    }

    private function compiler(): GraphCompiler
    {
        return new GraphCompiler(Paths::fromCwd($this->project->root));
    }

    private function createFeature(string $feature, string $method, string $path): void
    {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

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
  permissions: []
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
  emit: []
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
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: []\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/context.manifest.json', '{"version":1,"feature":"' . $feature . '","kind":"http"}');
        file_put_contents($base . '/tests/' . $feature . '_contract_test.php', '<?php declare(strict_types=1);');
    }
}
