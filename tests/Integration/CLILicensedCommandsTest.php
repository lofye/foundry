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

final class CLILicensedCommandsTest extends TestCase
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

    public function test_core_capability_commands_run_without_license(): void
    {
        $app = new Application();

        $status = $this->runCommand($app, ['foundry', 'license', 'status', '--json']);
        $this->assertSame(0, $status['status']);
        $this->assertFalse($status['payload']['license']['valid']);

        $explain = $this->runCommand($app, ['foundry', 'explain', 'publish_post', '--json']);
        $this->assertSame(0, $explain['status']);
        $this->assertSame('feature:publish_post', $explain['payload']['subject']['id']);
        $this->assertArrayHasKey('confidence', $explain['payload']);

        $deep = $this->runCommand($app, ['foundry', 'doctor', '--deep', '--json']);
        $this->assertSame(0, $deep['status']);
        $this->assertTrue($deep['payload']['deep']);
        $this->assertArrayHasKey('deep_diagnostics', $deep['payload']['monetization']);

        $generate = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--mode=new', '--dry-run', '--json']);
        $this->assertSame(0, $generate['status']);
        $this->assertTrue($generate['payload']['ok']);
        $this->assertSame('new', $generate['payload']['mode']);
        $this->assertSame('core.feature.new', $generate['payload']['plan']['generator_id']);
        $this->assertArrayHasKey('confidence', $generate['payload']['plan']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);

        $explainText = $this->runCommandRaw($app, ['foundry', 'explain', 'publish_post']);
        $this->assertSame(0, $explainText['status']);
        $this->assertStringContainsString('Subject', $explainText['output']);
        $this->assertStringContainsString('Confidence', $explainText['output']);

        $help = $this->runCommandRaw($app, ['foundry', 'help']);
        $this->assertSame(0, $help['status']);
        $this->assertStringNotContainsString(' [Licensed]', $help['output']);
        $this->assertStringNotContainsString('requires a license', $help['output']);
        $this->assertStringNotContainsString('[Pro]', $help['output']);
    }

    public function test_explain_trace_deep_and_diff_run_with_valid_local_license(): void
    {
        $app = new Application();

        $this->activateLicense($app);

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
        $this->assertArrayHasKey('confidence', $explain['payload']);

        $trace = $this->runCommand($app, ['foundry', 'trace', 'publish', '--json']);
        $this->assertSame(0, $trace['status']);
        $this->assertSame(2, $trace['payload']['matched_events']);

        $deep = $this->runCommand($app, ['foundry', 'doctor', '--deep', '--json']);
        $this->assertSame(0, $deep['status']);
        $this->assertTrue($deep['payload']['deep']);
        $this->assertArrayHasKey('deep_diagnostics', $deep['payload']['monetization']);

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
        $this->activateLicense($app);

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
        $this->assertStringContainsString('### Confidence', $markdown['output']);
        $this->assertStringContainsString('### Summary', $markdown['output']);
        $this->assertStringContainsString('### Execution Flow', $markdown['output']);
        $this->assertStringContainsString('### Related Docs', $markdown['output']);

        $default = $this->runCommand($app, ['foundry', 'explain', '--json']);
        $this->assertSame(0, $default['status']);
        $this->assertSame('feature', $default['payload']['subject']['kind']);
        $this->assertSame('feature:publish_post', $default['payload']['subject']['id']);
    }

    public function test_explain_reports_unsupported_kind_and_ambiguous_targets_cleanly(): void
    {
        $app = new Application();
        $this->activateLicense($app);

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

    public function test_generate_requires_mode_and_target_contracts(): void
    {
        $app = new Application();
        $this->activateLicense($app);

        $generate = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--dry-run', '--json']);

        $this->assertSame(1, $generate['status']);
        $this->assertSame('GENERATE_MODE_REQUIRED', $generate['payload']['error']['code']);

        $modify = $this->runCommand($app, ['foundry', 'generate', 'Update', 'publish', 'copy', '--mode=modify', '--json']);
        $this->assertSame(1, $modify['status']);
        $this->assertSame('GENERATE_TARGET_REQUIRED', $modify['payload']['error']['code']);
    }

    public function test_generate_new_dry_run_is_repeatable_and_explain_driven(): void
    {
        $app = new Application();
        $this->activateLicense($app);

        $first = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--mode=new', '--dry-run', '--json']);
        $second = $this->runCommand($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--mode=new', '--dry-run', '--json']);

        $this->assertSame(0, $first['status']);
        $this->assertSame(0, $second['status']);
        $this->assertSame($first['payload']['plan'], $second['payload']['plan']);
        $this->assertSame('core.feature.new', $first['payload']['plan']['generator_id']);
        $this->assertSame('bookmark_post', $first['payload']['plan']['metadata']['feature']);
        $this->assertSame('/posts/{id}/bookmark', $first['payload']['plan']['metadata']['execution']['feature_definition']['route']['path']);
        $this->assertSame('feature:publish_post', $first['payload']['metadata']['target']['resolved']);
        $this->assertTrue($first['payload']['verification_results']['ok']);
        $this->assertSame([], $first['payload']['actions_taken']);
    }

    public function test_generate_modify_updates_feature_metadata_and_verifies_graph(): void
    {
        $app = new Application();
        $this->activateLicense($app);

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Add',
            'moderation',
            'notes',
            '--mode=modify',
            '--target=publish_post',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertTrue($generate['payload']['ok']);
        $this->assertSame('core.feature.modify', $generate['payload']['plan']['generator_id']);
        $this->assertTrue($generate['payload']['verification_results']['ok']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);
        $featureYaml = (string) file_get_contents($this->project->root . '/app/features/publish_post/feature.yaml');
        $prompts = (string) file_get_contents($this->project->root . '/app/features/publish_post/prompts.md');
        $this->assertStringContainsString('Modification intent: Add moderation notes.', $featureYaml);
        $this->assertStringContainsString('Latest generate intent: Add moderation notes', $prompts);
    }

    public function test_generate_repair_restores_missing_context_manifest_and_tests(): void
    {
        $app = new Application();
        $this->activateLicense($app);
        @unlink($this->project->root . '/app/features/publish_post/context.manifest.json');
        @unlink($this->project->root . '/app/features/publish_post/tests/publish_post_auth_test.php');

        $generate = $this->runCommand($app, [
            'foundry',
            'generate',
            'Restore',
            'missing',
            'generated',
            'artifacts',
            '--mode=repair',
            '--target=publish_post',
            '--json',
        ]);

        $this->assertSame(0, $generate['status']);
        $this->assertTrue($generate['payload']['ok']);
        $this->assertSame('core.feature.repair', $generate['payload']['plan']['generator_id']);
        $this->assertTrue($generate['payload']['verification_results']['ok']);
        $this->assertArrayHasKey('plan_confidence', $generate['payload']);
        $this->assertArrayHasKey('outcome_confidence', $generate['payload']);
        $this->assertFileExists($this->project->root . '/app/features/publish_post/context.manifest.json');
        $this->assertFileExists($this->project->root . '/app/features/publish_post/tests/publish_post_auth_test.php');
    }

    public function test_generate_emits_human_readable_messages_without_json(): void
    {
        $app = new Application();
        $this->activateLicense($app);

        $dryRun = $this->runCommandRaw($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--mode=new', '--dry-run']);
        $this->assertSame(0, $dryRun['status']);
        $this->assertStringContainsString('Generate plan prepared.', $dryRun['output']);
        $this->assertStringContainsString('Generator: core.feature.new', $dryRun['output']);

        $generated = $this->runCommandRaw($app, ['foundry', 'generate', 'Add', 'bookmark', 'support', '--mode=new']);
        $this->assertSame(0, $generated['status']);
        $this->assertStringContainsString('Generate completed.', $generated['output']);
        $this->assertStringContainsString('Verification: passed', $generated['output']);
    }

    private function validKey(): string
    {
        $body = 'FPRO-ABCD-EFGH-IJKL-MNOP';

        return $body . '-' . strtoupper(substr(hash('sha256', 'foundry-pro:' . $body), 0, 8));
    }

    private function activateLicense(Application $app): void
    {
        $enable = $this->runCommand($app, ['foundry', 'license', 'activate', '--key=' . $this->validKey(), '--json']);
        $this->assertSame(0, $enable['status']);
        $this->assertTrue($enable['payload']['license']['valid']);
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
