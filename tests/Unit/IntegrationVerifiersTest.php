<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use Foundry\Verification\ApiVerifier;
use Foundry\Verification\NotificationsVerifier;
use PHPUnit\Framework\TestCase;

final class IntegrationVerifiersTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        $this->createFeature('api_list_posts', 'GET', '/api/posts');
        $this->createFeature('dispatch_welcome_email', 'POST', '/dispatch/welcome');

        mkdir($this->project->root . '/app/definitions/api', 0777, true);
        mkdir($this->project->root . '/app/definitions/notifications', 0777, true);
        mkdir($this->project->root . '/app/notifications/schemas', 0777, true);
        mkdir($this->project->root . '/app/notifications/templates', 0777, true);

        file_put_contents($this->project->root . '/app/definitions/api/posts.api-resource.yaml', <<<'YAML'
version: 1
resource: posts
style: api
features: [list]
feature_names:
  list: api_list_posts
YAML);

        file_put_contents($this->project->root . '/app/definitions/notifications/welcome_email.notification.yaml', <<<'YAML'
version: 1
notification: welcome_email
channel: mail
queue: default
template: welcome_email
input_schema: app/notifications/schemas/welcome_email.input.schema.json
dispatch_features: [dispatch_welcome_email]
YAML);

        file_put_contents($this->project->root . '/app/notifications/schemas/welcome_email.input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"required":["user_id"],"properties":{"user_id":{"type":"string"}}}');
        file_put_contents($this->project->root . '/app/notifications/templates/welcome_email.mail.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'subject' => 'Welcome {{user_id}}',
    'text' => 'Welcome {{user_id}}',
    'html' => '<p>Welcome {{user_id}}</p>',
];
PHP);

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $compiler->compile(new CompileOptions());
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_notifications_verifier_passes_for_valid_notifications(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $result = (new NotificationsVerifier(new GraphCompiler($paths), $paths))->verify();

        $this->assertTrue($result->ok);
    }

    public function test_notifications_verifier_reports_invalid_configuration_and_warnings(): void
    {
        file_put_contents($this->project->root . '/app/definitions/notifications/welcome_email.notification.yaml', <<<'YAML'
version: 1
notification: welcome_email
channel: sms
queue: ""
template: missing_template
input_schema: app/notifications/schemas/missing.input.schema.json
dispatch_features: []
YAML);
        @unlink($this->project->root . '/app/notifications/templates/welcome_email.mail.php');

        $paths = Paths::fromCwd($this->project->root);
        $result = (new NotificationsVerifier(new GraphCompiler($paths), $paths))->verify('welcome_email');

        $this->assertFalse($result->ok);
        $this->assertContains('Notification welcome_email uses unsupported channel sms.', $result->errors);
        $this->assertContains('Notification welcome_email template does not exist: app/notifications/templates/missing_template.mail.php', $result->errors);
        $this->assertContains('Notification welcome_email input schema missing.', $result->errors);
        $this->assertContains('Notification welcome_email is not linked to any dispatch feature.', $result->warnings);
    }

    public function test_notifications_verifier_reports_missing_notification_name(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $result = (new NotificationsVerifier(new GraphCompiler($paths), $paths))->verify('missing_notification');

        $this->assertFalse($result->ok);
        $this->assertContains('Notification not found in compiled graph: missing_notification', $result->errors);
    }

    public function test_api_verifier_passes_for_valid_api_resource(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $result = (new ApiVerifier(new GraphCompiler($paths)))->verify('posts');

        $this->assertTrue($result->ok);
    }

    public function test_api_verifier_reports_missing_features_and_invalid_route_prefix(): void
    {
        $this->createFeature('api_bad_create', 'POST', '/posts');

        file_put_contents($this->project->root . '/app/definitions/api/posts.api-resource.yaml', <<<'YAML'
version: 1
resource: posts
style: api
features: [list, create]
feature_names:
  list: missing_list_feature
  create: api_bad_create
YAML);

        $paths = Paths::fromCwd($this->project->root);
        $result = (new ApiVerifier(new GraphCompiler($paths)))->verify('posts');

        $this->assertFalse($result->ok);
        $this->assertContains('API resource posts missing feature missing_list_feature.', $result->errors);
        $this->assertContains('API feature api_bad_create route must start with /api (got /posts).', $result->errors);
    }

    public function test_api_verifier_reports_resource_not_found(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $result = (new ApiVerifier(new GraphCompiler($paths)))->verify('missing_posts');

        $this->assertFalse($result->ok);
        $this->assertContains('API resource not found in compiled graph: missing_posts', $result->errors);
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
  strategies: [bearer]
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
