<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Auth\RolePolicyRegistry;
use Foundry\Billing\BillingPlanRegistry;
use Foundry\Localization\LocaleCatalog;
use Foundry\Orchestration\OrchestrationPlanner;
use Foundry\Realtime\SseEmitter;
use Foundry\Search\SearchManager;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use Foundry\Workflow\WorkflowEngine;
use PHPUnit\Framework\TestCase;

final class PlatformRuntimeFoundationsTest extends TestCase
{
    private TempProject $project;
    private Paths $paths;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->paths = Paths::fromCwd($this->project->root);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_billing_plan_registry_reads_projection_and_provider_lookup(): void
    {
        $registry = new BillingPlanRegistry($this->paths);
        $this->assertSame([], $registry->all());
        $this->assertSame([], $registry->provider('stripe'));

        mkdir($this->project->root . '/app/.foundry/build/projections', 0777, true);
        file_put_contents($this->project->root . '/app/.foundry/build/projections/billing_index.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'stripe' => ['provider' => 'stripe', 'plans' => ['starter' => ['price_id' => 'price_starter']]],
];
PHP);

        $loaded = new BillingPlanRegistry($this->paths);
        $this->assertArrayHasKey('stripe', $loaded->all());
        $this->assertSame('stripe', $loaded->provider('Stripe')['provider']);
    }

    public function test_workflow_engine_enforces_transitions_permissions_and_unknown_transition(): void
    {
        $engine = new WorkflowEngine([
            'transitions' => [
                'publish' => ['from' => ['review'], 'to' => 'published', 'permission' => 'posts.publish', 'emit' => ['post.published']],
            ],
        ]);

        $this->assertTrue($engine->canTransition('review', 'publish', ['posts.publish']));
        $this->assertFalse($engine->canTransition('draft', 'publish', ['posts.publish']));
        $this->assertFalse($engine->canTransition('review', 'publish', []));
        $this->assertFalse($engine->canTransition('review', 'missing', ['posts.publish']));

        $applied = $engine->apply('review', 'publish', ['posts.publish']);
        $this->assertSame('published', $applied['to']);
        $this->assertSame(['post.published'], $applied['emit']);

        try {
            $engine->apply('review', 'missing', ['posts.publish']);
            $this->fail('Expected unknown transition error.');
        } catch (FoundryError $error) {
            $this->assertSame('WORKFLOW_TRANSITION_UNKNOWN', $error->errorCode);
        }

        try {
            $engine->apply('draft', 'publish', ['posts.publish']);
            $this->fail('Expected transition denied error.');
        } catch (FoundryError $error) {
            $this->assertSame('WORKFLOW_TRANSITION_DENIED', $error->errorCode);
        }
    }

    public function test_orchestration_planner_orders_steps_and_detects_unknown_or_cycle_dependencies(): void
    {
        $planner = new OrchestrationPlanner();
        $order = $planner->topologicalOrder([
            ['name' => 'extract_text', 'depends_on' => []],
            ['name' => 'generate_summary', 'depends_on' => ['extract_text']],
            ['name' => 'classify_document', 'depends_on' => ['extract_text']],
            ['name' => 'finalize', 'depends_on' => ['classify_document', 'generate_summary']],
        ]);

        $this->assertSame('extract_text', $order[0]);
        $this->assertSame('finalize', $order[3]);

        try {
            $planner->topologicalOrder([
                ['name' => 'a', 'depends_on' => ['missing']],
            ]);
            $this->fail('Expected unknown dependency error.');
        } catch (FoundryError $error) {
            $this->assertSame('ORCHESTRATION_DEPENDENCY_UNKNOWN', $error->errorCode);
        }

        try {
            $planner->topologicalOrder([
                ['name' => 'a', 'depends_on' => ['b']],
                ['name' => 'b', 'depends_on' => ['a']],
            ]);
            $this->fail('Expected orchestration cycle error.');
        } catch (FoundryError $error) {
            $this->assertSame('ORCHESTRATION_CYCLE', $error->errorCode);
        }
    }

    public function test_search_manager_uses_sql_and_meilisearch_adapters_and_rejects_unknown(): void
    {
        $rows = [
            ['id' => '1', 'title' => 'Hello World', 'status' => 'published'],
            ['id' => '2', 'title' => 'Draft note', 'status' => 'draft'],
        ];

        $manager = new SearchManager();
        $sql = $manager->query('sql', $rows, 'hello', ['title'], ['status' => 'published']);
        $this->assertCount(1, $sql);
        $this->assertSame('1', $sql[0]['id']);

        $meili = $manager->query('meilisearch', $rows, 'draft', ['title']);
        $this->assertCount(1, $meili);
        $this->assertSame('2', $meili[0]['id']);

        try {
            $manager->query('unknown', $rows, '', ['title']);
            $this->fail('Expected unknown adapter error.');
        } catch (FoundryError $error) {
            $this->assertSame('SEARCH_ADAPTER_UNKNOWN', $error->errorCode);
        }
    }

    public function test_sse_emitter_renders_deterministic_payload(): void
    {
        $sse = new SseEmitter();
        $payload = $sse->render([
            ['id' => 'evt-1', 'event' => 'progress', 'data' => ['percent' => 50]],
            ['event' => 'done', 'data' => ['ok' => true]],
        ], 3000);

        $this->assertStringContainsString("retry: 3000\n", $payload);
        $this->assertStringContainsString("id: evt-1\n", $payload);
        $this->assertStringContainsString("event: progress\n", $payload);
        $this->assertStringContainsString('data: {"percent":50}', $payload);
        $this->assertStringContainsString("event: done\n", $payload);
    }

    public function test_locale_catalog_loads_and_falls_back_by_key(): void
    {
        mkdir($this->project->root . '/lang/en', 0777, true);
        mkdir($this->project->root . '/lang/fr', 0777, true);

        file_put_contents($this->project->root . '/lang/en/messages.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['greeting' => 'Hello', 'farewell' => 'Bye'];
PHP);
        file_put_contents($this->project->root . '/lang/fr/messages.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['greeting' => 'Bonjour'];
PHP);

        $catalog = new LocaleCatalog($this->paths);
        $this->assertSame('Bonjour', $catalog->translate('fr', 'greeting'));
        $this->assertSame('Bye', $catalog->translate('fr', 'farewell'));
        $this->assertSame('unknown.key', $catalog->translate('fr', 'unknown.key'));
        $this->assertSame([], $catalog->load('es'));
    }

    public function test_role_policy_registry_allows_wildcards_and_policy_rules(): void
    {
        mkdir($this->project->root . '/app/.foundry/build/projections', 0777, true);
        file_put_contents($this->project->root . '/app/.foundry/build/projections/role_index.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'admin' => ['role' => 'admin', 'permissions' => ['*']],
    'viewer' => ['role' => 'viewer', 'permissions' => ['posts.view']],
];
PHP);
        file_put_contents($this->project->root . '/app/.foundry/build/projections/policy_index.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'posts' => ['policy' => 'posts', 'rules' => ['editor' => ['posts.update']]],
];
PHP);

        $registry = new RolePolicyRegistry($this->paths);
        $this->assertArrayHasKey('admin', $registry->roles());
        $this->assertArrayHasKey('posts', $registry->policies());

        $this->assertTrue($registry->allows('admin', 'anything.do'));
        $this->assertTrue($registry->allows('viewer', 'posts.view'));
        $this->assertFalse($registry->allows('viewer', 'posts.update'));
        $this->assertTrue($registry->allows('editor', 'posts.update', 'posts'));
        $this->assertFalse($registry->allows('editor', 'posts.delete', 'posts'));
    }
}
