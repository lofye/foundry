<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\Concerns\InteractsWithGraphInspection;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GraphInspectionSupportTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->seedProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_trait_parses_options_builds_payload_and_resolves_export_paths(): void
    {
        $helper = new class {
            use InteractsWithGraphInspection {
                parseGraphOptions as public;
                buildGraphInspectionPayload as public;
                renderGraphInspectionMessage as public;
                graphExportPath as public;
                loadOrCompileGraphForInspection as public;
                normalizeGraphOption as public;
                sanitizeGraphFilePart as public;
            }
        };

        $options = $helper->parseGraphOptions([
            'inspect',
            'graph',
            '--workflows',
            '--view',
            'command',
            '--feature=publish_post',
            '--extension=core',
            '--pipeline=auth',
            '--pipeline-stage',
            'validation',
            '--command',
            'POST /posts',
            '--event=post.created',
            '--workflow',
            'posts',
            '--area',
            'routes',
            '--format',
            'dot',
            '--output',
            'docs/graph.dot',
        ]);

        $this->assertSame('pipeline', $options['view']);
        $this->assertSame('publish_post', $options['feature']);
        $this->assertSame('core', $options['extension']);
        $this->assertSame('validation', $options['pipeline']);
        $this->assertSame('POST /posts', $options['command']);
        $this->assertSame('post.created', $options['event']);
        $this->assertSame('posts', $options['workflow']);
        $this->assertSame('routes', $options['area']);
        $this->assertSame('dot', $options['format']);
        $this->assertSame('docs/graph.dot', $options['output']);

        $context = new CommandContext($this->project->root, true);
        $graph = $helper->loadOrCompileGraphForInspection($context);
        $this->assertTrue($graph->hasNode('feature:publish_post'));

        $payload = $helper->buildGraphInspectionPayload($context, [
            'command' => 'POST /posts',
            'format' => 'dot',
        ]);

        $this->assertSame('command', $payload['view']);
        $this->assertSame('POST /posts', $payload['command_filter']);
        $this->assertStringContainsString('digraph foundry', (string) $payload['rendered']);

        $summaryMessage = $helper->renderGraphInspectionMessage($payload, false, '/tmp/foundry-graph.json');
        $this->assertStringContainsString('Command graph for POST /posts', $summaryMessage);
        $this->assertStringContainsString('file: /tmp/foundry-graph.json', $summaryMessage);

        $renderedMessage = $helper->renderGraphInspectionMessage($payload, true);
        $this->assertStringContainsString('digraph foundry', $renderedMessage);

        $relativePath = $helper->graphExportPath($context, $payload, 'json', 'docs/graph.json');
        $this->assertSame($this->project->root . '/docs/graph.json', $relativePath);

        $absolutePath = $helper->graphExportPath($context, $payload, 'json', '/tmp/foundry-graph.json');
        $this->assertSame('/tmp/foundry-graph.json', $absolutePath);

        $defaultPath = $helper->graphExportPath($context, $payload, 'json');
        $this->assertSame(
            $this->project->root . '/app/.foundry/build/exports/graph.command.post-posts.json',
            $defaultPath,
        );

        $this->assertNull($helper->normalizeGraphOption('   '));
        $this->assertSame('workflow:posts', $helper->normalizeGraphOption(' workflow:posts '));
        $this->assertSame('post-posts', $helper->sanitizeGraphFilePart('POST /posts'));
    }

    private function seedProject(): void
    {
        $base = $this->project->root . '/app/features/publish_post';
        mkdir($base . '/tests', 0777, true);
        mkdir($this->project->root . '/app/definitions/workflows', 0777, true);

        file_put_contents($base . '/feature.yaml', <<<'YAML'
version: 1
feature: publish_post
kind: http
description: publish
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
  queries: []
cache:
  reads: []
  writes: []
  invalidate: [posts:list]
events:
  emit: [post.created]
  subscribe: []
jobs:
  dispatch: []
rate_limit: {}
tests:
  required: [feature]
llm:
  editable: true
  risk: low
YAML);
        file_put_contents($base . '/input.schema.json', '{"type":"object"}');
        file_put_contents($base . '/output.schema.json', '{"type":"object"}');
        file_put_contents($base . '/queries.sql', '');
        file_put_contents($base . '/permissions.yaml', "version: 1\npermissions: [posts.create]\nrules: {}\n");
        file_put_contents($base . '/cache.yaml', "version: 1\nentries:\n  - key: posts:list\n    kind: computed\n    ttl_seconds: 120\n    invalidated_by: [publish_post]\n");
        file_put_contents($base . '/events.yaml', <<<'YAML'
version: 1
emit:
  - name: post.created
    schema:
      type: object
      additionalProperties: false
      properties: {}
subscribe: []
YAML);
        file_put_contents($base . '/jobs.yaml', "version: 1\ndispatch: []\n");
        file_put_contents($base . '/tests/publish_post_feature_test.php', '<?php declare(strict_types=1);');

        file_put_contents($this->project->root . '/app/definitions/workflows/posts.workflow.yaml', <<<'YAML'
version: 1
resource: posts
states: [draft, published]
transitions:
  publish:
    from: [draft]
    to: published
    permission: posts.create
    emit: [post.created]
YAML);
    }
}
