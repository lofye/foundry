<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIConfigValidationTest extends TestCase
{
    private TempProject $project;
    private string $cwd;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        $base = $this->project->root . '/app/features/list_posts';
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 2
feature: list_posts
kind: http
description: list_posts
route:
  method: GET
  path: /posts
input:
  schema: app/features/list_posts/input.schema.json
output:
  schema: app/features/list_posts/output.schema.json
auth:
  required: false
  public: true
  strategies: []
  permissions: []
database:
  reads: []
  writes: []
  transactions: optional
  queries: []
cache:
  reads: []
  writes: []
  invalidate: []
events:
  emit: []
  subscribe: []
jobs:
  dispatch: []
rate_limit: {}
tests:
  required: []
llm:
  editable: true
  risk_level: low
YAML);

        file_put_contents($base . '/input.schema.json', '{"type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/output.schema.json', '{"type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($base . '/action.php', '<?php declare(strict_types=1);');
        file_put_contents($base . '/queries.sql', '');
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: []\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($base . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");

        mkdir($this->project->root . '/config', 0777, true);
        file_put_contents($this->project->root . '/config/queue.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'redis',
    'drivers' => [
        'redis' => [
            'connection' => 123,
        ],
    ],
];
PHP);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_doctor_exposes_config_validation_payload(): void
    {
        $result = $this->runCommand(new Application(), ['foundry', 'doctor', '--json']);

        $this->assertSame(1, $result['status']);
        $this->assertGreaterThan(0, (int) ($result['payload']['config_validation']['summary']['error'] ?? 0));
        $issue = (array) (($result['payload']['config_validation']['items'][0] ?? []));
        $this->assertSame('config.queue', $issue['schema_id']);
        $this->assertSame('$.drivers.redis.connection', $issue['config_path']);
        $this->assertSame('string', $issue['expected']);
        $this->assertSame('integer(123)', $issue['actual']);
        $this->assertArrayHasKey('path', $result['payload']['config_schemas']);
    }

    /**
     * @param array<int,string> $argv
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
}
