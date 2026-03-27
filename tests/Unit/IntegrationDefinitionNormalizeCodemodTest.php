<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Codemod\IntegrationDefinitionNormalizeCodemod;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class IntegrationDefinitionNormalizeCodemodTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        mkdir($this->project->root . '/app/definitions/notifications', 0777, true);
        mkdir($this->project->root . '/app/definitions/api', 0777, true);

        file_put_contents($this->project->root . '/app/definitions/notifications/welcome.notification.yaml', <<<'YAML'
notification: welcome_email
channel: mail
YAML);

        file_put_contents($this->project->root . '/app/definitions/api/posts.api-resource.yaml', <<<'YAML'
resource: posts
style: api
YAML);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_dry_run_reports_changes_for_integration_definitions(): void
    {
        $codemod = new IntegrationDefinitionNormalizeCodemod();
        $result = $codemod->run(Paths::fromCwd($this->project->root), false);

        $this->assertSame('integration-definition-v1-normalize', $result->codemod);
        $this->assertFalse($result->written);
        $this->assertCount(2, $result->changes);
    }

    public function test_write_mode_applies_version_field(): void
    {
        $codemod = new IntegrationDefinitionNormalizeCodemod();
        $result = $codemod->run(Paths::fromCwd($this->project->root), true);

        $this->assertTrue($result->written);

        $notification = file_get_contents($this->project->root . '/app/definitions/notifications/welcome.notification.yaml') ?: '';
        $api = file_get_contents($this->project->root . '/app/definitions/api/posts.api-resource.yaml') ?: '';

        $this->assertStringContainsString('version: 1', $notification);
        $this->assertStringContainsString('version: 1', $api);
    }
}
