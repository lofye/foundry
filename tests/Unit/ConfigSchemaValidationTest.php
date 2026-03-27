<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Core\RuntimeFactory;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ConfigSchemaValidationTest extends TestCase
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

    public function test_compile_emits_machine_readable_config_schema_and_validation_artifacts(): void
    {
        $this->seedFeature('list_posts');
        $this->seedCanonicalConfig();
        mkdir($this->project->root . '/app/definitions/search', 0777, true);
        file_put_contents($this->project->root . '/app/definitions/search/posts.search.yaml', <<<'YAML'
version: 1
index: posts
adapter: sql
resource: posts
source:
  table: posts
  primary_key: id
fields: [title, slug]
filters: [status]
YAML);

        $compiler = new GraphCompiler(Paths::fromCwd($this->project->root));
        $result = $compiler->compile(new CompileOptions());

        $this->assertSame(0, (int) ($result->configValidation['summary']['error'] ?? 1));

        $schemaArtifact = $this->project->root . '/app/.foundry/build/manifests/config_schemas.json';
        $validationArtifact = $this->project->root . '/app/.foundry/build/diagnostics/config_validation.json';

        $this->assertFileExists($schemaArtifact);
        $this->assertFileExists($validationArtifact);

        $schemas = json_decode((string) file_get_contents($schemaArtifact), true, 512, JSON_THROW_ON_ERROR);
        $validation = json_decode((string) file_get_contents($validationArtifact), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('config.app', $schemas['schemas']);
        $this->assertArrayHasKey('routing.route', $schemas['schemas']);
        $this->assertArrayHasKey('definition.search_index', $schemas['schemas']);
        $this->assertArrayHasKey('extension.descriptor', $schemas['schemas']);
        $this->assertSame(0, $validation['summary']['error']);
        $this->assertContains('config/app.php', $validation['validated_sources']);
        $this->assertContains('app/features/list_posts/feature.yaml', $validation['validated_sources']);
    }

    public function test_compile_reports_actionable_invalid_config_errors(): void
    {
        $this->seedFeature('list_posts');
        mkdir($this->project->root . '/config', 0777, true);
        file_put_contents($this->project->root . '/config/queue.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'redis',
    'drivers' => [
        'redis' => [
            'connection' => 5,
        ],
    ],
];
PHP);

        $result = (new GraphCompiler(Paths::fromCwd($this->project->root)))->compile(new CompileOptions());

        $this->assertGreaterThan(0, (int) ($result->configValidation['summary']['error'] ?? 0));

        $issue = $this->firstConfigIssue($result->configValidation['items'], 'FDY1703_CONFIG_SCHEMA_VIOLATION');
        $this->assertNotNull($issue);
        $this->assertSame('config.queue', $issue['schema_id']);
        $this->assertSame('$.drivers.redis.connection', $issue['config_path']);
        $this->assertSame('string', $issue['expected']);
        $this->assertSame('integer(5)', $issue['actual']);
        $this->assertStringContainsString('Replace the value', (string) $issue['suggested_fix']);
    }

    public function test_compile_accepts_legacy_config_aliases_with_upgrade_warnings(): void
    {
        $this->seedFeature('list_posts');
        mkdir($this->project->root . '/config', 0777, true);

        file_put_contents($this->project->root . '/config/database.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'sqlite',
    'sqlite' => [
        'dsn' => 'sqlite::memory:',
    ],
];
PHP);

        file_put_contents($this->project->root . '/config/storage.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'local',
    'local_root' => 'storage/files',
];
PHP);

        file_put_contents($this->project->root . '/config/ai.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default_provider' => 'static',
];
PHP);

        $result = (new GraphCompiler(Paths::fromCwd($this->project->root)))->compile(new CompileOptions());

        $this->assertSame(0, (int) ($result->configValidation['summary']['error'] ?? 1));
        $this->assertGreaterThanOrEqual(3, (int) ($result->configValidation['summary']['warning'] ?? 0));

        $messages = array_values(array_map(
            static fn (array $item): string => (string) ($item['message'] ?? ''),
            (array) ($result->configValidation['items'] ?? []),
        ));

        $this->assertTrue($this->containsMessage($messages, 'normalized into $.connections.sqlite'));
        $this->assertTrue($this->containsMessage($messages, 'normalized into $.root'));
        $this->assertTrue($this->containsMessage($messages, 'normalized into $.default'));
    }

    public function test_runtime_factory_rejects_invalid_config_with_structured_details(): void
    {
        mkdir($this->project->root . '/config', 0777, true);
        file_put_contents($this->project->root . '/config/storage.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'local',
    'root' => 123,
];
PHP);

        try {
            RuntimeFactory::httpKernel(Paths::fromCwd($this->project->root));
            self::fail('Expected config validation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('CONFIG_VALIDATION_FAILED', $error->errorCode);
            $this->assertSame('validation', $error->category);
            $this->assertArrayHasKey('errors', $error->details);
            $first = (array) (($error->details['errors'][0] ?? []));
            $this->assertSame('$.root', $first['config_path']);
            $this->assertSame('string', $first['expected']);
            $this->assertSame('integer(123)', $first['actual']);
        }
    }

    private function seedCanonicalConfig(): void
    {
        mkdir($this->project->root . '/bootstrap', 0777, true);
        mkdir($this->project->root . '/config', 0777, true);

        file_put_contents($this->project->root . '/bootstrap/app.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'name' => 'Test App',
    'env' => 'test',
    'debug' => true,
];
PHP);

        file_put_contents($this->project->root . '/bootstrap/providers.php', <<<'PHP'
<?php
declare(strict_types=1);

return [];
PHP);

        file_put_contents($this->project->root . '/config/app.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'name' => 'Test App',
    'routing' => [
        'base_path' => '',
        'trailing_slash_strategy' => 'ignore',
    ],
];
PHP);

        file_put_contents($this->project->root . '/config/auth.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'bearer',
    'development_header' => 'x-user-id',
    'strategies' => [
        'bearer' => [
            'header' => 'x-user-id',
        ],
    ],
];
PHP);

        file_put_contents($this->project->root . '/config/database.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'dsn' => 'sqlite::memory:',
        ],
    ],
];
PHP);

        file_put_contents($this->project->root . '/config/cache.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'array',
];
PHP);

        file_put_contents($this->project->root . '/config/queue.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'sync',
];
PHP);

        file_put_contents($this->project->root . '/config/storage.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'local',
    'root' => 'storage/files',
];
PHP);

        file_put_contents($this->project->root . '/config/ai.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'default' => 'static',
];
PHP);
    }

    private function seedFeature(string $feature): void
    {
        $base = $this->project->root . '/app/features/' . $feature;
        mkdir($base . '/tests', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<YAML
version: 2
feature: {$feature}
kind: http
description: {$feature}
route:
  method: GET
  path: /posts
input:
  schema: app/features/{$feature}/input.schema.json
output:
  schema: app/features/{$feature}/output.schema.json
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
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function firstConfigIssue(array $items, string $code): ?array
    {
        foreach ($items as $item) {
            if ((string) ($item['code'] ?? '') === $code) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $messages
     */
    private function containsMessage(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
