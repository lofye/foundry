<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GenerateMetricsStore;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GenerateMetricsStoreTest extends TestCase
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

    public function test_disabled_by_default(): void
    {
        $store = new GenerateMetricsStore(new Paths($this->project->root));
        $this->assertFalse($store->enabled());
    }

    public function test_appends_records_and_computes_deterministic_aggregates(): void
    {
        $this->writeMetricsConfig(['metrics' => ['enabled' => true]]);
        $store = new GenerateMetricsStore(new Paths($this->project->root));

        $store->append([
            'record_id' => '11111111-1111-4111-8111-111111111111',
            'type' => 'single',
            'template_id' => 'feature.recipe',
            'workflow_id' => null,
            'steps' => 0,
            'status' => 'completed',
            'policy_violations' => 1,
            'approval_required' => true,
            'approval_status' => 'approved',
            'timestamp' => null,
        ]);
        $store->append([
            'record_id' => '22222222-2222-4222-8222-222222222222',
            'type' => 'workflow',
            'template_id' => null,
            'workflow_id' => 'workflow-abc',
            'steps' => 3,
            'status' => 'failed',
            'policy_violations' => 0,
            'approval_required' => false,
            'approval_status' => null,
            'timestamp' => null,
        ]);

        $records = $store->list();
        $this->assertCount(2, $records);
        $this->assertSame(0, $records[0]['entry_index']);
        $this->assertSame(1, $records[1]['entry_index']);
        $this->assertSame($records[0]['entry_digest'], $records[1]['previous_digest']);

        $this->assertSame(
            [
                'total_runs' => 2,
                'failures' => 1,
                'failure_rate' => 50.0,
                'average_steps' => 3.0,
                'templates' => ['feature.recipe' => 1],
                'approvals' => ['required' => 1, 'approved' => 1, 'pending' => 0, 'rejected' => 0],
                'policy_violations' => 1,
            ],
            $store->aggregates(),
        );
        $this->assertSame([], $store->verify());
    }

    public function test_verify_fails_when_entry_order_is_corrupted(): void
    {
        $this->writeMetricsConfig(['metrics' => ['enabled' => true]]);
        $store = new GenerateMetricsStore(new Paths($this->project->root));
        $store->append([
            'record_id' => '11111111-1111-4111-8111-111111111111',
            'type' => 'single',
            'template_id' => null,
            'workflow_id' => null,
            'steps' => 0,
            'status' => 'completed',
            'policy_violations' => 0,
            'approval_required' => false,
            'approval_status' => null,
            'timestamp' => null,
        ]);

        $path = $this->project->root . '/.foundry/metrics/generate-metrics.json';
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $payload['records'][0]['entry_index'] = 99;
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

        $issues = $store->verify();
        $this->assertNotSame([], $issues);
        $this->assertSame('GENERATE_METRICS_ORDER_CORRUPTED', $issues[0]['code']);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeMetricsConfig(array $payload): void
    {
        $dir = $this->project->root . '/.foundry/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $dir . '/metrics.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }
}
