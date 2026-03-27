<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\FeatureNode;
use Foundry\Compiler\IR\GuardNode;
use Foundry\Compiler\IR\InterceptorNode;
use Foundry\Compiler\IR\PipelineStageNode;
use Foundry\Compiler\IR\RouteNode;
use Foundry\Pipeline\PipelineIntegrityInspector;
use PHPUnit\Framework\TestCase;

final class PipelineIntegrityInspectorTest extends TestCase
{
    public function test_inspector_reports_structured_pipeline_issues(): void
    {
        $graph = new ApplicationGraph(
            graphVersion: 1,
            frameworkVersion: '1.0.0',
            compiledAt: gmdate(DATE_ATOM),
            sourceHash: 'source-hash',
        );

        $graph->addNode(new FeatureNode('feature:publish_post', 'app/features/publish_post/feature.yaml', ['feature' => 'publish_post']));
        $graph->addNode(new RouteNode('route:POST /posts', 'app/features/publish_post/feature.yaml', ['feature' => 'publish_post', 'signature' => 'POST /posts']));
        $graph->addNode(new GuardNode('guard:auth:publish_post', 'app/features/publish_post/feature.yaml', ['feature' => 'publish_post']));
        $graph->addNode(new InterceptorNode('interceptor:broken', 'config/app.php', ['stage' => 'missing_stage']));
        $graph->addNode(new PipelineStageNode('pipeline_stage:before_auth', 'config/app.php', ['name' => 'before_auth']));
        $graph->addNode(new PipelineStageNode('pipeline_stage:action', 'config/app.php', ['name' => 'action']));

        $report = (new PipelineIntegrityInspector())->inspect($graph, 'publish_post');
        $codes = array_values(array_map(
            static fn (array $issue): string => (string) ($issue['code'] ?? ''),
            (array) ($report['issues'] ?? []),
        ));
        sort($codes);

        $this->assertContains('FDY9115_PIPELINE_STAGE_MISSING', $codes);
        $this->assertContains('FDY9116_PIPELINE_STAGE_ORDER_MISSING', $codes);
        $this->assertContains('FDY9117_EXECUTION_PLANS_MISSING', $codes);
        $this->assertContains('FDY9118_ROUTE_EXECUTION_PLAN_MISSING', $codes);
        $this->assertContains('FDY9119_FEATURE_EXECUTION_PLAN_MISSING', $codes);
        $this->assertContains('FDY9123_GUARD_STAGE_MISSING', $codes);
        $this->assertContains('FDY9124_INTERCEPTOR_STAGE_UNKNOWN', $codes);
        $this->assertSame(2, $report['summary']['stage_count']);
        $this->assertSame(0, $report['summary']['execution_plan_count']);
    }
}
