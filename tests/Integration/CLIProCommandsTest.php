<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use Foundry\CLI\Application;
use Foundry\Generation\ContextManifestGenerator;
use Foundry\Generation\IndexGenerator;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class CLIProCommandsTest extends TestCase
{
    private TempProject $project;
    private string $cwd;
    private ?string $previousFoundryHome = null;
    private ?string $previousLicensePath = null;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->cwd = getcwd() ?: '.';
        chdir($this->project->root);

        $this->previousFoundryHome = getenv('FOUNDRY_HOME') !== false ? (string) getenv('FOUNDRY_HOME') : null;
        $this->previousLicensePath = getenv('FOUNDRY_LICENSE_PATH') !== false ? (string) getenv('FOUNDRY_LICENSE_PATH') : null;
        putenv('FOUNDRY_HOME=' . $this->project->root . '/.foundry-home');
        putenv('FOUNDRY_LICENSE_PATH');
        mkdir($this->project->root . '/.foundry-home', 0777, true);
        mkdir($this->project->root . '/config', 0777, true);
        mkdir($this->project->root . '/docs', 0777, true);

        $feature = $this->project->root . '/app/features/publish_post';
        mkdir($feature . '/tests', 0777, true);

        file_put_contents($this->project->root . '/docs/architecture-tools.md', "# Architecture Tools\n");
        file_put_contents($this->project->root . '/docs/execution-pipeline.md', "# Execution Pipeline\n");
        file_put_contents($this->project->root . '/docs/how-it-works.md', "# How It Works\n");
        file_put_contents($this->project->root . '/docs/reference.md', "# Reference\n");
        file_put_contents($this->project->root . '/docs/extension-author-guide.md', "# Extension Author Guide\n");
        file_put_contents($this->project->root . '/docs/extensions-and-migrations.md', "# Extensions And Migrations\n");
        file_put_contents($this->project->root . '/docs/public-api-policy.md', "# Public API Policy\n");

        file_put_contents($feature . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_post
kind: http
description: test
route:
  method: POST
  path: /posts
input:
  schema: app/features/publish_post/input.schema.json
output:
  schema: app/features/publish_post/output.schema.json
auth:
  required: true
  strategies: [bearer]
  permissions: [posts.create]
database:
  reads: []
  writes: []
  transactions: required
  queries: [insert_post]
cache:
  reads: []
  writes: []
  invalidate: [posts:list]
events:
  emit: [post.created]
jobs:
  dispatch: [notify_followers]
rate_limit: {}
tests:
  required: [contract, feature, auth]
llm:
  editable: true
  risk: medium
YAML);

        file_put_contents($feature . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($feature . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($feature . '/action.php', '<?php declare(strict_types=1); namespace App\\Features\\PublishPost; use Foundry\\Feature\\FeatureAction; use Foundry\\Feature\\FeatureServices; use Foundry\\Auth\\AuthContext; use Foundry\\Http\\RequestContext; final class Action implements FeatureAction { public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array { return []; } }');
        file_put_contents($feature . '/queries.sql', "-- name: insert_post\nINSERT INTO posts(id) VALUES(:id);\n");
        file_put_contents($feature . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($feature . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 300\n    invalidated_by: [publish_post]\n");
        file_put_contents($feature . '/events.yaml', "version: 1\nemit:\n  - name: post.created\n    schema:\n      type: object\n      additionalProperties: false\n      properties: {}\nsubscribe: []\n");
        file_put_contents($feature . '/jobs.yaml', "version: 1\ndispatch:\n  - name: notify_followers\n    input_schema:\n      type: object\n      additionalProperties: false\n      properties: {}\n    queue: default\n    retry:\n      max_attempts: 2\n      backoff_seconds: [1,2]\n    timeout_seconds: 30\n\n");
        file_put_contents($feature . '/tests/publish_post_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($feature . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');
        file_put_contents($feature . '/tests/publish_post_auth_test.php', '<?php declare(strict_types=1);');
        file_put_contents($this->project->root . '/storage/logs/trace.log', "publish:started\npublish:finished\ncache:flush\n");

        $paths = Paths::fromCwd($this->project->root);
        (new IndexGenerator($paths))->generate();
        $manifest = Yaml::parseFile($feature . '/feature.yaml');
        (new ContextManifestGenerator($paths))->write('publish_post', $manifest);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('FOUNDRY_HOME', $this->previousFoundryHome);
        $this->restoreEnv('FOUNDRY_LICENSE_PATH', $this->previousLicensePath);
        chdir($this->cwd);
        $this->project->cleanup();
    }

    public function test_pro_commands_are_gated_without_license(): void
    {
        $app = new Application();

        $status = $this->runCommand($app, ['foundry', 'pro', 'status', '--json']);
        $this->assertSame(0, $status['status']);
        $this->assertFalse($status['payload']['license']['valid']);

        $explain = $this->runCommand($app, ['foundry', 'explain', 'publish_post', '--json']);
        $this->assertSame(1, $explain['status']);
        $this->assertSame('PRO_LICENSE_REQUIRED', $explain['payload']['error']['code']);

        $deep = $this->runCommand($app, ['foundry', 'doctor', '--deep', '--json']);
        $this->assertSame(1, $deep['status']);
        $this->assertSame('PRO_LICENSE_REQUIRED', $deep['payload']['error']['code']);

        $generate = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--json']);
        $this->assertSame(1, $generate['status']);
        $this->assertSame('PRO_LICENSE_REQUIRED', $generate['payload']['error']['code']);

        $help = $this->runCommandRaw($app, ['foundry', 'help']);
        $this->assertSame(0, $help['status']);
        $this->assertStringContainsString(' [Pro]', $help['output']);
        $this->assertStringContainsString('generate <prompt> [Pro]', $help['output']);
    }

    public function test_explain_trace_deep_and_diff_run_with_valid_local_license(): void
    {
        $app = new Application();

        $this->enablePro($app);

        $compile = $this->runCommand($app, ['foundry', 'compile', 'graph', '--json']);
        $this->assertSame(0, $compile['status']);

        $explain = $this->runCommand($app, ['foundry', 'explain', 'publish_post', '--json']);
        $this->assertSame(0, $explain['status']);
        $this->assertSame('feature:publish_post', $explain['payload']['subject']['id']);
        $this->assertSame('feature', $explain['payload']['subject']['kind']);
        $this->assertSame('publish_post', $explain['payload']['executionFlow']['action']['feature']);
        $this->assertNotEmpty($explain['payload']['executionFlow']['guards']);
        $this->assertNotEmpty($explain['payload']['executionFlow']['events']);
        $this->assertNotEmpty($explain['payload']['relatedDocs']);
        $this->assertSame('publish_post', $explain['payload']['metadata']['target']['selector']);

        $trace = $this->runCommand($app, ['foundry', 'trace', 'publish', '--json']);
        $this->assertSame(0, $trace['status']);
        $this->assertSame(2, $trace['payload']['matched_events']);

        $deep = $this->runCommand($app, ['foundry', 'doctor', '--deep', '--json']);
        $this->assertSame(0, $deep['status']);
        $this->assertTrue($deep['payload']['deep']);
        $this->assertArrayHasKey('deep_diagnostics', $deep['payload']['pro']);

        file_put_contents(
            $this->project->root . '/app/features/publish_post/feature.yaml',
            str_replace('description: test', 'description: updated test', (string) file_get_contents($this->project->root . '/app/features/publish_post/feature.yaml')),
        );

        $diff = $this->runCommand($app, ['foundry', 'diff', '--json']);
        $this->assertSame(0, $diff['status']);
        $this->assertGreaterThanOrEqual(1, $diff['payload']['summary']['changed_nodes']);
    }

    public function test_explain_supports_type_markdown_and_disable_flags(): void
    {
        $app = new Application();
        $this->enablePro($app);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'compile', 'graph', '--json'])['status']);

        $typed = $this->runCommand($app, ['foundry', 'explain', 'POST', '/posts', '--type=route', '--no-flow', '--no-neighbors', '--no-diagnostics', '--json']);
        $this->assertSame(0, $typed['status']);
        $this->assertSame('route', $typed['payload']['subject']['kind']);
        $this->assertSame([
            'entries' => [],
            'stages' => [],
            'guards' => [],
            'action' => null,
            'events' => [],
            'workflows' => [],
            'jobs' => [],
        ], $typed['payload']['executionFlow']);
        $this->assertSame([
            'inbound' => [],
            'outbound' => [],
            'lateral' => [],
        ], $typed['payload']['relationships']['graph']);
        $this->assertSame(0, $typed['payload']['diagnostics']['summary']['total']);

        $markdown = $this->runCommandRaw($app, ['foundry', 'explain', 'POST', '/posts', '--type', 'route', '--markdown']);
        $this->assertSame(0, $markdown['status']);
        $this->assertStringContainsString('## POST /posts', $markdown['output']);
        $this->assertStringContainsString('### Summary', $markdown['output']);
        $this->assertStringContainsString('### Execution Flow', $markdown['output']);
        $this->assertStringContainsString('### Related Docs', $markdown['output']);

        $missing = $this->runCommand($app, ['foundry', 'explain', '--json']);
        $this->assertSame(1, $missing['status']);
        $this->assertSame('EXPLAIN_TARGET_REQUIRED', $missing['payload']['error']['code']);
    }

    public function test_explain_reports_unsupported_kind_and_ambiguous_targets_cleanly(): void
    {
        $app = new Application();
        $this->enablePro($app);

        $feature = $this->project->root . '/app/features/publish_profile';
        mkdir($feature . '/tests', 0777, true);
        file_put_contents($feature . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_profile
kind: http
description: profile publish
route:
  method: POST
  path: /profiles
input:
  schema: app/features/publish_profile/input.schema.json
output:
  schema: app/features/publish_profile/output.schema.json
auth:
  required: true
  strategies: [bearer]
  permissions: [profiles.create]
database:
  reads: []
  writes: []
  transactions: required
  queries: []
cache:
  reads: []
  writes: []
  invalidate: []
events:
  emit: []
jobs:
  dispatch: []
rate_limit: {}
tests:
  required: [contract, feature]
llm:
  editable: true
  risk: low
YAML);
        file_put_contents($feature . '/input.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($feature . '/output.schema.json', '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","additionalProperties":false,"properties":{}}');
        file_put_contents($feature . '/action.php', '<?php declare(strict_types=1); namespace App\\Features\\PublishProfile; use Foundry\\Feature\\FeatureAction; use Foundry\\Feature\\FeatureServices; use Foundry\\Auth\\AuthContext; use Foundry\\Http\\RequestContext; final class Action implements FeatureAction { public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array { return []; } }');
        file_put_contents($feature . '/permissions.yaml', "version: 1\npermissions: [profiles.create]\nrules: {}\n");
        file_put_contents($feature . '/cache.yaml', "version: 1\nentries: []\n");
        file_put_contents($feature . '/events.yaml', "version: 1\nemit: []\nsubscribe: []\n");
        file_put_contents($feature . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($feature . '/tests/publish_profile_contract_test.php', '<?php declare(strict_types=1);');
        file_put_contents($feature . '/tests/publish_profile_feature_test.php', '<?php declare(strict_types=1);');

        $paths = Paths::fromCwd($this->project->root);
        $manifest = Yaml::parseFile($feature . '/feature.yaml');
        (new ContextManifestGenerator($paths))->write('publish_profile', $manifest);

        $this->assertSame(0, $this->runCommand($app, ['foundry', 'compile', 'graph', '--json'])['status']);

        $unsupported = $this->runCommand($app, ['foundry', 'explain', 'unknown:thing', '--json']);
        $this->assertSame(1, $unsupported['status']);
        $this->assertSame('EXPLAIN_TARGET_KIND_UNSUPPORTED', $unsupported['payload']['error']['code']);

        $ambiguous = $this->runCommandRaw($app, ['foundry', 'explain', 'publish']);
        $this->assertSame(1, $ambiguous['status']);
        $this->assertStringContainsString('Ambiguous target: "publish"', $ambiguous['output']);
        $this->assertStringContainsString('publish_post (feature)', $ambiguous['output']);
        $this->assertStringContainsString('publish_profile (feature)', $ambiguous['output']);
    }

    public function test_generate_requires_provider_or_deterministic_mode(): void
    {
        $app = new Application();
        $this->enablePro($app);

        $generate = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--dry-run', '--json']);

        $this->assertSame(1, $generate['status']);
        $this->assertSame('PRO_GENERATE_PROVIDER_NOT_CONFIGURED', $generate['payload']['error']['code']);
    }

    public function test_deterministic_generate_dry_run_is_repeatable_and_graph_aware(): void
    {
        $app = new Application();
        $this->enablePro($app);

        $first = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--feature-context', '--deterministic', '--dry-run', '--json']);
        $second = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--feature-context', '--deterministic', '--dry-run', '--json']);

        $this->assertSame(0, $first['status']);
        $this->assertSame(0, $second['status']);
        $this->assertSame($first['payload']['plan'], $second['payload']['plan']);
        $this->assertSame($first['payload']['predicted_files'], $second['payload']['predicted_files']);
        $this->assertSame('pro_generate', $first['payload']['mode']);
        $this->assertTrue($first['payload']['deterministic']);
        $this->assertSame('deterministic', $first['payload']['provider']['mode']);
        $this->assertSame('bookmark_post', $first['payload']['plan']['feature']['feature']);
        $this->assertSame('/posts/{id}/bookmark', $first['payload']['plan']['feature']['route']['path']);
        $this->assertSame(['publish_post'], $first['payload']['plan']['trace']['selected_features']);
        $this->assertNotEmpty($first['payload']['predicted_files']);
    }

    public function test_provider_backed_generate_uses_local_provider_config(): void
    {
        $app = new Application();
        $this->enablePro($app);
        $this->writeAiConfig([
            'default' => 'static',
            'providers' => [
                'static' => [
                    'driver' => 'static',
                    'model' => 'fixture-model',
                    'parsed' => [
                        'feature' => [
                            'feature' => 'favorite_post',
                            'description' => 'Favorite a post.',
                            'kind' => 'http',
                            'route' => ['method' => 'POST', 'path' => '/posts/{id}/favorite'],
                            'input' => ['fields' => ['id' => ['type' => 'string', 'required' => true]]],
                            'output' => ['fields' => [
                                'id' => ['type' => 'string', 'required' => true],
                                'status' => ['type' => 'string', 'required' => true],
                            ]],
                            'auth' => ['required' => true, 'strategies' => ['bearer'], 'permissions' => ['posts.favorite']],
                            'database' => ['reads' => ['posts'], 'writes' => ['posts'], 'queries' => ['favorite_post'], 'transactions' => 'required'],
                            'cache' => ['reads' => [], 'writes' => [], 'invalidate' => ['posts:list']],
                            'events' => ['emit' => ['post.favorited'], 'subscribe' => []],
                            'jobs' => ['dispatch' => []],
                            'tests' => ['required' => ['contract', 'feature', 'auth']],
                        ],
                        'explanation' => 'Provider plan.',
                    ],
                    'input_tokens' => 12,
                    'output_tokens' => 8,
                    'cost_estimate' => 0.42,
                    'metadata' => ['source' => 'fixture'],
                ],
            ],
        ]);

        $generate = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--provider', 'static', '--model', 'override-model', '--dry-run', '--json']);

        $this->assertSame(0, $generate['status']);
        $this->assertFalse($generate['payload']['deterministic']);
        $this->assertSame('provider', $generate['payload']['provider']['mode']);
        $this->assertSame('static', $generate['payload']['provider']['provider']);
        $this->assertSame('override-model', $generate['payload']['provider']['model']);
        $this->assertSame(12, $generate['payload']['provider']['input_tokens']);
        $this->assertSame(8, $generate['payload']['provider']['output_tokens']);
        $this->assertSame('favorite_post', $generate['payload']['plan']['feature']['feature']);
        $this->assertSame('/posts/{id}/favorite', $generate['payload']['plan']['feature']['route']['path']);
        $this->assertSame('Provider plan.', $generate['payload']['plan']['explanation']);
    }

    public function test_deterministic_generate_writes_feature_and_verifies_graph(): void
    {
        $app = new Application();
        $this->enablePro($app);

        $generate = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--deterministic', '--json']);

        $this->assertSame(0, $generate['status']);
        $this->assertTrue($generate['payload']['ok']);
        $this->assertTrue($generate['payload']['verification']['graph']['ok']);
        $this->assertTrue($generate['payload']['verification']['contracts']['ok']);
        $this->assertFileExists($this->project->root . '/app/features/bookmark_post/feature.yaml');
        $generatedFeatureManifest = false;
        foreach ((array) $generate['payload']['generated']['files'] as $file) {
            if (str_ends_with((string) $file, '/app/features/bookmark_post/feature.yaml')) {
                $generatedFeatureManifest = true;
                break;
            }
        }
        $this->assertTrue($generatedFeatureManifest);

        $inspect = $this->runCommand($app, ['foundry', 'inspect', 'feature', 'bookmark_post', '--json']);
        $this->assertSame(0, $inspect['status']);
        $this->assertSame('bookmark_post', $inspect['payload']['feature']);

        $pipeline = $this->runCommand($app, ['foundry', 'verify', 'pipeline', '--json']);
        $this->assertSame(0, $pipeline['status']);
        $this->assertTrue($pipeline['payload']['ok']);
    }

    public function test_generate_emits_human_readable_messages_without_json(): void
    {
        $app = new Application();
        $this->enablePro($app);

        $dryRun = $this->runCommandRaw($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--deterministic', '--dry-run']);
        $this->assertSame(0, $dryRun['status']);
        $this->assertStringContainsString('Foundry Pro generation plan prepared for bookmark_post.', $dryRun['output']);

        $generated = $this->runCommandRaw($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--deterministic']);
        $this->assertSame(0, $generated['status']);
        $this->assertStringContainsString('Foundry Pro generated bookmark_post using deterministic mode and wrote', $generated['output']);
    }

    private function validKey(): string
    {
        $body = 'FPRO-ABCD-EFGH-IJKL-MNOP';

        return $body . '-' . strtoupper(substr(hash('sha256', 'foundry-pro:' . $body), 0, 8));
    }

    private function enablePro(Application $app): void
    {
        $enable = $this->runCommand($app, ['foundry', 'pro', 'enable', $this->validKey(), '--json']);
        $this->assertSame(0, $enable['status']);
        $this->assertTrue($enable['payload']['license']['valid']);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeAiConfig(array $config): void
    {
        file_put_contents(
            $this->project->root . '/config/ai.php',
            "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n",
        );
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
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

    /**
     * @param array<int,string> $argv
     * @return array{status:int,output:string}
     */
    private function runCommandRaw(Application $app, array $argv): array
    {
        ob_start();
        $status = $app->run($argv);
        $output = (string) (ob_get_clean() ?: '');

        return ['status' => $status, 'output' => $output];
    }
}
