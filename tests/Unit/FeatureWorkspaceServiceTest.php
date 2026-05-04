<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\FeatureSystem\FeatureWorkspaceService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class FeatureWorkspaceServiceTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_list_prefers_canonical_layout_and_sorts_deterministically(): void
    {
        $this->writeFile('Features/EventSystem/event-system.spec.md', '# Feature Spec: event-system');
        $this->writeFile('Features/EventSystem/event-system.md', '# Feature: event-system');
        $this->writeFile('Features/EventSystem/event-system.decisions.md', $this->minimalDecision());
        mkdir($this->project->root . '/Features/EventSystem/specs', 0777, true);
        mkdir($this->project->root . '/Features/EventSystem/src', 0777, true);
        mkdir($this->project->root . '/Features/EventSystem/tests', 0777, true);

        $this->writeFile('docs/features/extension-system/extension-system.spec.md', '# Feature Spec: extension-system');
        $this->writeFile('docs/features/extension-system/extension-system.md', '# Feature: extension-system');
        $this->writeFile('docs/features/extension-system/extension-system.decisions.md', $this->minimalDecision());
        mkdir($this->project->root . '/docs/features/extension-system/specs', 0777, true);

        $list = $this->service()->list();

        $this->assertSame(['event-system', 'extension-system'], array_column($list['features'], 'slug'));
        $this->assertSame('Features/EventSystem', $list['features'][0]['path']);
        $this->assertSame('docs/features/extension-system', $list['features'][1]['path']);
    }

    public function test_inspect_reads_manifest_dependencies_in_sorted_order(): void
    {
        $this->writeFile('Features/EventSystem/event-system.spec.md', '# Feature Spec: event-system');
        $this->writeFile('Features/EventSystem/event-system.md', '# Feature: event-system');
        $this->writeFile('Features/EventSystem/event-system.decisions.md', $this->minimalDecision());
        $this->writeFile('Features/EventSystem/feature.json', json_encode([
            'slug' => 'event-system',
            'name' => 'EventSystem',
            'dependencies' => ['mcp-server', 'extension-system', 'mcp-server'],
            'boundary' => ['enforced' => true],
        ], JSON_THROW_ON_ERROR));

        $payload = $this->service()->inspect('event-system');

        $this->assertSame(
            ['extension-system', 'mcp-server'],
            $payload['feature']['dependencies'],
        );
    }

    public function test_verify_reports_duplicate_canonical_and_legacy_features(): void
    {
        $this->writeFile('Features/EventSystem/event-system.spec.md', '# Feature Spec: event-system');
        $this->writeFile('Features/EventSystem/event-system.md', '# Feature: event-system');
        $this->writeFile('Features/EventSystem/event-system.decisions.md', $this->minimalDecision());

        $this->writeFile('docs/features/event-system/event-system.spec.md', '# Feature Spec: event-system');
        $this->writeFile('docs/features/event-system/event-system.md', '# Feature: event-system');
        $this->writeFile('docs/features/event-system/event-system.decisions.md', $this->minimalDecision());

        $payload = $this->service()->verify();

        $this->assertSame('failed', $payload['status']);
        $this->assertSame('FEATURE_DUPLICATE_CANONICAL_AND_LEGACY', $payload['violations'][0]['code']);
    }

    public function test_verify_emits_warning_when_boundary_enforcement_disabled(): void
    {
        $this->writeFile('.foundry/config/features.json', json_encode(['enforce_boundaries' => false], JSON_THROW_ON_ERROR));

        $payload = $this->service()->verify();

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('disabled', $payload['enforcement']);
        $this->assertSame('FEATURE_BOUNDARY_ENFORCEMENT_DISABLED', $payload['warnings'][0]['code']);
    }

    private function service(): FeatureWorkspaceService
    {
        return new FeatureWorkspaceService(new Paths($this->project->root));
    }

    private function writeFile(string $relativePath, string $contents): void
    {
        $absolutePath = $this->project->root . '/' . $relativePath;
        $directory = dirname($absolutePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, $contents);
    }

    private function minimalDecision(): string
    {
        return <<<'MD'
### Decision: baseline

Timestamp: 2026-05-03T10:00:00-04:00

**Context**

- baseline

**Decision**

- baseline

**Reasoning**

- baseline

**Alternatives Considered**

- baseline

**Impact**

- baseline

**Spec Reference**

- baseline
MD;
    }
}
