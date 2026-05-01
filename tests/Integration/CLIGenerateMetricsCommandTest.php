<?php

declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIGenerateMetricsCommandTest extends TestCase
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

    public function test_generate_metrics_returns_deterministic_json_aggregates(): void
    {
        $this->writeMetricsRecord();
        $app = new Application();

        $json = $this->runCommand($app, ['foundry', 'generate:metrics', '--json']);
        $this->assertSame(0, $json['status']);
        $this->assertSame(2, $json['payload']['total_runs']);
        $this->assertSame(1, $json['payload']['failures']);
        $this->assertSame(50, $json['payload']['failure_rate']);
        $this->assertSame(3, $json['payload']['average_steps']);
        $this->assertSame(['feature.recipe' => 1], $json['payload']['templates']);
    }

    public function test_generate_metrics_fails_when_records_are_invalid(): void
    {
        $dir = $this->project->root . '/.foundry/metrics';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/generate-metrics.json', "{\"schema\":\"foundry.generate.metrics_store.v1\",\"records\":[{\"broken\":true}]}\n");
        $app = new Application();

        $json = $this->runCommand($app, ['foundry', 'generate:metrics', '--json']);
        $this->assertSame(1, $json['status']);
        $this->assertSame('GENERATE_METRICS_INVALID', $json['payload']['error']['code']);
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function runCommand(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = ob_get_clean() ?: '';
        /** @var array<string,mixed> $payload */
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'payload' => $payload];
    }

    private function writeMetricsRecord(): void
    {
        $dir = $this->project->root . '/.foundry/metrics';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $records = [
            [
                'schema' => 'foundry.generate.metrics_record.v1',
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
                'entry_index' => 0,
                'previous_digest' => null,
            ],
            [
                'schema' => 'foundry.generate.metrics_record.v1',
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
                'entry_index' => 1,
                'previous_digest' => null,
            ],
        ];

        $records[0]['entry_digest'] = hash('sha256', json_encode([
            'schema' => $records[0]['schema'],
            'record_id' => $records[0]['record_id'],
            'type' => $records[0]['type'],
            'template_id' => $records[0]['template_id'],
            'workflow_id' => $records[0]['workflow_id'],
            'steps' => $records[0]['steps'],
            'status' => $records[0]['status'],
            'policy_violations' => $records[0]['policy_violations'],
            'approval_required' => $records[0]['approval_required'],
            'approval_status' => $records[0]['approval_status'],
            'timestamp' => $records[0]['timestamp'],
            'entry_index' => $records[0]['entry_index'],
            'previous_digest' => $records[0]['previous_digest'],
        ], JSON_THROW_ON_ERROR));
        $records[1]['previous_digest'] = $records[0]['entry_digest'];
        $records[1]['entry_digest'] = hash('sha256', json_encode([
            'schema' => $records[1]['schema'],
            'record_id' => $records[1]['record_id'],
            'type' => $records[1]['type'],
            'template_id' => $records[1]['template_id'],
            'workflow_id' => $records[1]['workflow_id'],
            'steps' => $records[1]['steps'],
            'status' => $records[1]['status'],
            'policy_violations' => $records[1]['policy_violations'],
            'approval_required' => $records[1]['approval_required'],
            'approval_status' => $records[1]['approval_status'],
            'timestamp' => $records[1]['timestamp'],
            'entry_index' => $records[1]['entry_index'],
            'previous_digest' => $records[1]['previous_digest'],
        ], JSON_THROW_ON_ERROR));

        file_put_contents(
            $dir . '/generate-metrics.json',
            json_encode(['schema' => 'foundry.generate.metrics_store.v1', 'records' => $records], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }
}
