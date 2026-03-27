<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Codemod\PlatformDefinitionNormalizeCodemod;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class PlatformDefinitionNormalizeCodemodTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();

        mkdir($this->project->root . '/app/definitions/billing', 0777, true);
        mkdir($this->project->root . '/app/definitions/workflows', 0777, true);
        mkdir($this->project->root . '/app/definitions/orchestrations', 0777, true);
        mkdir($this->project->root . '/app/definitions/search', 0777, true);
        mkdir($this->project->root . '/app/definitions/streams', 0777, true);
        mkdir($this->project->root . '/app/definitions/locales', 0777, true);
        mkdir($this->project->root . '/app/definitions/roles', 0777, true);
        mkdir($this->project->root . '/app/definitions/policies', 0777, true);
        mkdir($this->project->root . '/app/definitions/inspect-ui', 0777, true);

        file_put_contents($this->project->root . '/app/definitions/billing/stripe.billing.yaml', <<<'YAML'
provider: stripe
plans:
  - key: starter
    price_id: price_starter
YAML);

        file_put_contents($this->project->root . '/app/definitions/workflows/posts.workflow.yaml', <<<'YAML'
resource: posts
transitions: {}
states: [draft]
YAML);

        file_put_contents($this->project->root . '/app/definitions/orchestrations/process.orchestration.yaml', <<<'YAML'
name: process
steps: []
YAML);

        file_put_contents($this->project->root . '/app/definitions/search/posts.search.yaml', <<<'YAML'
index: posts
fields: [title]
YAML);

        file_put_contents($this->project->root . '/app/definitions/streams/progress.stream.yaml', <<<'YAML'
stream: progress
route:
  path: /streams/progress
YAML);

        file_put_contents($this->project->root . '/app/definitions/locales/core.locale.yaml', <<<'YAML'
bundle: core
default: en
locales: [en]
YAML);

        file_put_contents($this->project->root . '/app/definitions/roles/default.roles.yaml', <<<'YAML'
set: default
roles: {}
YAML);

        file_put_contents($this->project->root . '/app/definitions/policies/posts.policy.yaml', <<<'YAML'
policy: posts
rules: {}
YAML);

        file_put_contents($this->project->root . '/app/definitions/inspect-ui/dev.inspect-ui.yaml', <<<'YAML'
name: dev
sections: [features]
YAML);

        // Intentional parse error to exercise diagnostics path.
        file_put_contents($this->project->root . '/app/definitions/workflows/bad.workflow.yaml', "version: [\n");
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_dry_run_reports_changes_and_parse_diagnostics(): void
    {
        $codemod = new PlatformDefinitionNormalizeCodemod();
        $result = $codemod->run(Paths::fromCwd($this->project->root), false);

        $this->assertSame('platform-definition-v1-normalize', $result->codemod);
        $this->assertFalse($result->written);
        $this->assertGreaterThanOrEqual(9, count($result->changes));
        $this->assertContains('billing_definition', array_values(array_map(
            static fn(array $row): string => (string) ($row['format'] ?? ''),
            $result->changes,
        )));
        $this->assertContains('FDY2485_PLATFORM_DEFINITION_PARSE_ERROR', array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $result->diagnostics,
        )));
    }

    public function test_write_mode_and_path_filter_apply_deterministic_rewrite(): void
    {
        $codemod = new PlatformDefinitionNormalizeCodemod();
        $path = 'app/definitions/billing/stripe.billing.yaml';
        $result = $codemod->run(Paths::fromCwd($this->project->root), true, $path);

        $this->assertTrue($result->written);
        $this->assertCount(1, $result->changes);
        $this->assertSame($path, $result->changes[0]['path']);

        $updated = file_get_contents($this->project->root . '/' . $path) ?: '';
        $this->assertStringContainsString('version: 1', $updated);
        $this->assertStringContainsString('provider: stripe', $updated);
    }
}
