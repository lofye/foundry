<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIFeatureSystemCommandTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_feature_commands_are_deterministic_and_return_expected_shapes(): void
    {
        $this->writeContext('Features/EventSystem', 'event-system');
        mkdir($this->project->root . '/Features/EventSystem/specs', 0777, true);
        mkdir($this->project->root . '/Features/EventSystem/src', 0777, true);
        mkdir($this->project->root . '/Features/EventSystem/tests', 0777, true);
        $this->writeFile('Features/EventSystem/src/EventRegistry.php', "<?php\n");
        $this->writeFile('Features/EventSystem/tests/EventRegistryTest.php', "<?php\n");
        $this->writeFile('Features/EventSystem/feature.json', json_encode([
            'slug' => 'event-system',
            'name' => 'EventSystem',
            'dependencies' => ['mcp-server', 'extension-system'],
            'boundary' => ['enforced' => true],
        ], JSON_THROW_ON_ERROR));

        $listFirst = $this->runCommand(['foundry', 'feature:list', '--json']);
        $listSecond = $this->runCommand(['foundry', 'feature:list', '--json']);
        $mapFirst = $this->runCommand(['foundry', 'feature:map', '--json']);
        $mapSecond = $this->runCommand(['foundry', 'feature:map', '--json']);
        $inspect = $this->runCommand(['foundry', 'feature:inspect', 'event-system', '--json']);

        $this->assertSame($listFirst['payload'], $listSecond['payload']);
        $this->assertSame($mapFirst['payload'], $mapSecond['payload']);
        $this->assertSame('event-system', $listFirst['payload']['features'][0]['slug']);
        $this->assertSame('Features/EventSystem', $listFirst['payload']['features'][0]['path']);
        $this->assertSame('Features/EventSystem/event-system.spec.md', $inspect['payload']['feature']['context']['spec']);
        $this->assertSame(['extension-system', 'mcp-server'], $inspect['payload']['feature']['dependencies']);
        $this->assertContains('Features/EventSystem/src/EventRegistry.php', $mapFirst['payload']['features'][0]['owned_paths']);
    }

    public function test_verify_features_reports_duplicate_canonical_and_legacy_feature(): void
    {
        $this->writeContext('Features/EventSystem', 'event-system');
        $this->writeContext('docs/features/event-system', 'event-system');

        $result = $this->runCommand(['foundry', 'verify', 'features', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertSame('failed', $result['payload']['status']);
        $this->assertSame('FEATURE_DUPLICATE_CANONICAL_AND_LEGACY', $result['payload']['violations'][0]['code']);
    }

    public function test_spec_validate_supports_canonical_features_workspace_and_implementation_log(): void
    {
        $this->writeFile(
            'Features/ExecutionSpecSystem/specs/001-canonical-layout.md',
            "# Execution Spec: 001-canonical-layout\n",
        );
        $this->writeFile(
            'Features/implementation.log',
            "## 2026-05-03 12:00:00 -0400\n- spec: execution-spec-system/001-canonical-layout.md\n",
        );

        $result = $this->runCommand(['foundry', 'spec:validate', '--json']);

        $this->assertSame(0, $result['status']);
        $this->assertTrue($result['payload']['ok']);
    }

    /**
     * @param array<int,string> $argv
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(array $argv): array
    {
        ob_start();
        $status = (new Application())->run($argv);
        $output = ob_get_clean() ?: '';

        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    private function writeContext(string $featureDirectory, string $featureSlug): void
    {
        $this->writeFile($featureDirectory . '/' . $featureSlug . '.spec.md', '# Feature Spec: ' . $featureSlug);
        $this->writeFile($featureDirectory . '/' . $featureSlug . '.md', '# Feature: ' . $featureSlug);
        $this->writeFile($featureDirectory . '/' . $featureSlug . '.decisions.md', <<<'MD'
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
MD);
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
}
