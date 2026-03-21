<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\CacheNode;
use Foundry\Compiler\IR\EventNode;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\JobNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Compiler\IR\SchemaNode;
use Foundry\Documentation\GraphDocsGenerator;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GraphDocsGeneratorTest extends TestCase
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

    public function test_generates_markdown_and_html_docs_from_graph(): void
    {
        $graph = new ApplicationGraph(1, '0.4.0', '2026-03-09T00:00:00+00:00', 'abc');
        $graph->addNode(new FeatureNode('feature:list_posts', 'app/features/list_posts/feature.yaml', [
            'feature' => 'list_posts',
            'kind' => 'http',
            'route' => ['method' => 'GET', 'path' => '/posts'],
            'input_schema_path' => 'app/features/list_posts/input.schema.json',
            'output_schema_path' => 'app/features/list_posts/output.schema.json',
            'auth' => ['required' => false, 'strategies' => [], 'permissions' => []],
            'database' => ['reads' => ['posts'], 'writes' => []],
            'events' => ['emit' => ['post.listed']],
            'jobs' => ['dispatch' => ['warm_posts_cache']],
            'tests' => ['required' => ['feature']],
            'description' => 'List posts.',
        ]));
        $graph->addNode(new RouteNode('route:GET:/posts', 'app/features/list_posts/feature.yaml', ['signature' => 'GET /posts', 'features' => ['list_posts']]));
        $graph->addNode(new SchemaNode('schema:app/features/list_posts/input.schema.json', 'app/features/list_posts/input.schema.json', ['path' => 'app/features/list_posts/input.schema.json', 'role' => 'input', 'feature' => 'list_posts']));
        $graph->addNode(new SchemaNode('schema:app/features/list_posts/output.schema.json', 'app/features/list_posts/output.schema.json', ['path' => 'app/features/list_posts/output.schema.json', 'role' => 'output', 'feature' => 'list_posts']));
        $graph->addNode(new EventNode('event:post.listed', 'app/features/list_posts/events.yaml', ['name' => 'post.listed', 'emitters' => ['list_posts'], 'subscribers' => []]));
        $graph->addNode(new JobNode('job:warm_posts_cache', 'app/features/list_posts/jobs.yaml', ['name' => 'warm_posts_cache', 'features' => ['list_posts']]));
        $graph->addNode(new CacheNode('cache:posts:list', 'app/features/list_posts/cache.yaml', ['key' => 'posts:list', 'invalidated_by' => ['list_posts']]));

        $generator = new GraphDocsGenerator(Paths::fromCwd($this->project->root), new ApiSurfaceRegistry());
        $markdown = $generator->generate($graph, 'markdown');
        $html = $generator->generate($graph, 'html');

        $this->assertSame('markdown', $markdown['format']);
        $this->assertSame('html', $html['format']);
        $this->assertFileExists($this->project->root . '/docs/generated/features.md');
        $this->assertFileExists($this->project->root . '/docs/generated/features.html');
        $this->assertFileExists($this->project->root . '/docs/generated/graph-overview.md');
        $this->assertFileExists($this->project->root . '/docs/generated/api-surface.md');
        $this->assertFileExists($this->project->root . '/docs/generated/cli-reference.md');
        $this->assertFileExists($this->project->root . '/docs/generated/upgrade-reference.md');

        $graphOverviewMd = file_get_contents($this->project->root . '/docs/generated/graph-overview.md') ?: '';
        $featuresMd = file_get_contents($this->project->root . '/docs/generated/features.md') ?: '';
        $routesMd = file_get_contents($this->project->root . '/docs/generated/routes.md') ?: '';
        $apiSurfaceMd = file_get_contents($this->project->root . '/docs/generated/api-surface.md') ?: '';
        $cliReferenceMd = file_get_contents($this->project->root . '/docs/generated/cli-reference.md') ?: '';
        $upgradeReferenceMd = file_get_contents($this->project->root . '/docs/generated/upgrade-reference.md') ?: '';
        $llmMd = file_get_contents($this->project->root . '/docs/generated/llm-workflow.md') ?: '';
        $featuresHtml = file_get_contents($this->project->root . '/docs/generated/features.html') ?: '';

        $this->assertStringContainsString('# Graph Overview', $graphOverviewMd);
        $this->assertStringContainsString('inspect graph --json', $graphOverviewMd);
        $this->assertStringContainsString('# Feature Catalog', $featuresMd);
        $this->assertStringContainsString('## list_posts', $featuresMd);
        $this->assertStringContainsString('GET /posts', $routesMd);
        $this->assertStringContainsString('# API Surface Policy', $apiSurfaceMd);
        $this->assertStringContainsString('Foundry\\Feature\\', $apiSurfaceMd);
        $this->assertStringContainsString('# CLI Reference', $cliReferenceMd);
        $this->assertStringContainsString('compile graph [stable]', $cliReferenceMd);
        $this->assertStringContainsString('graph inspect [stable]', $cliReferenceMd);
        $this->assertStringContainsString('graph visualize [stable]', $cliReferenceMd);
        $this->assertStringContainsString('export graph [stable]', $cliReferenceMd);
        $this->assertStringContainsString('# Upgrade Reference', $upgradeReferenceMd);
        $this->assertStringContainsString('php vendor/bin/foundry upgrade-check --json', $upgradeReferenceMd);
        $this->assertStringContainsString('Config compatibility aliases', $upgradeReferenceMd);
        $this->assertStringContainsString('Recommended commands:', $llmMd);
        $this->assertStringContainsString('<h1 id="feature-catalog">Feature Catalog</h1>', $featuresHtml);
    }
}
