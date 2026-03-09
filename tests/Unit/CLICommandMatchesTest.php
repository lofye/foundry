<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\Commands\GenerateFeatureCommand;
use Foundry\CLI\Commands\GenerateIndexesCommand;
use Foundry\CLI\Commands\GeneratePhaseOneCommand;
use Foundry\CLI\Commands\GraphVisualizeCommand;
use Foundry\CLI\Commands\ImpactCommand;
use Foundry\CLI\Commands\InitAppCommand;
use Foundry\CLI\Commands\InspectGraphCommand;
use Foundry\CLI\Commands\InspectFeatureCommand;
use Foundry\CLI\Commands\InspectResourceCommand;
use Foundry\CLI\Commands\InspectRouteCommand;
use Foundry\CLI\Commands\MigrateSpecsCommand;
use Foundry\CLI\Commands\DoctorCommand;
use Foundry\CLI\Commands\PromptCommand;
use Foundry\CLI\Commands\QueueWorkCommand;
use Foundry\CLI\Commands\ScheduleRunCommand;
use Foundry\CLI\Commands\ServeCommand;
use Foundry\CLI\Commands\CodemodRunCommand;
use Foundry\CLI\Commands\CompileGraphCommand;
use Foundry\CLI\Commands\VerifyCompatibilityCommand;
use Foundry\CLI\Commands\VerifyGraphCommand;
use Foundry\CLI\Commands\VerifyPipelineCommand;
use Foundry\CLI\Commands\VerifyContractsCommand;
use Foundry\CLI\Commands\VerifyFeatureCommand;
use Foundry\CLI\Commands\VerifyResourceCommand;
use PHPUnit\Framework\TestCase;

final class CLICommandMatchesTest extends TestCase
{
    public function test_matches_methods_cover_all_commands(): void
    {
        $this->assertTrue((new InspectFeatureCommand())->matches(['inspect', 'feature', 'x']));
        $this->assertTrue((new InspectResourceCommand())->matches(['inspect', 'resource', 'posts']));
        $this->assertFalse((new InspectFeatureCommand())->matches(['other']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'graph']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'impact', '--file=app/features/x/feature.yaml']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'dependencies', 'feature:x']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'extension', 'core']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'packs']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'pack', 'core.foundation']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'compatibility']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'spec-format', 'feature_manifest']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'pipeline']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'execution-plan', 'publish_post']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'guards', 'publish_post']));
        $this->assertTrue((new InspectGraphCommand())->matches(['inspect', 'interceptors', '--stage=auth']));
        $this->assertFalse((new InspectGraphCommand())->matches(['inspect', 'dependencies', 'x']));

        $this->assertTrue((new InspectRouteCommand())->matches(['inspect', 'route', 'GET', '/']));
        $this->assertTrue((new InitAppCommand())->matches(['init', 'app', './my-app']));
        $this->assertTrue((new GenerateFeatureCommand())->matches(['generate', 'feature', 'x.yaml']));
        $this->assertTrue((new GeneratePhaseOneCommand())->matches(['generate', 'starter', 'api']));
        $this->assertTrue((new GeneratePhaseOneCommand())->matches(['generate', 'resource', 'posts', '--spec=specs/posts.resource.yaml']));
        $this->assertTrue((new GeneratePhaseOneCommand())->matches(['generate', 'admin-resource', 'posts']));
        $this->assertTrue((new GeneratePhaseOneCommand())->matches(['generate', 'uploads', 'avatar']));
        $this->assertTrue((new GenerateIndexesCommand())->matches(['generate', 'indexes']));
        $this->assertTrue((new CompileGraphCommand())->matches(['compile', 'graph']));
        $this->assertTrue((new DoctorCommand())->matches(['doctor']));
        $this->assertTrue((new GraphVisualizeCommand())->matches(['graph', 'visualize']));
        $this->assertTrue((new PromptCommand())->matches(['prompt', 'add', 'feature']));
        $this->assertTrue((new VerifyFeatureCommand())->matches(['verify', 'feature', 'x']));
        $this->assertTrue((new VerifyResourceCommand())->matches(['verify', 'resource', 'posts']));
        $this->assertTrue((new VerifyContractsCommand())->matches(['verify', 'contracts']));
        $this->assertTrue((new VerifyGraphCommand())->matches(['verify', 'graph']));
        $this->assertTrue((new VerifyPipelineCommand())->matches(['verify', 'pipeline']));
        $this->assertTrue((new VerifyCompatibilityCommand())->matches(['verify', 'extensions']));
        $this->assertTrue((new VerifyCompatibilityCommand())->matches(['verify', 'compatibility']));
        $this->assertTrue((new ServeCommand())->matches(['serve']));
        $this->assertTrue((new QueueWorkCommand())->matches(['queue:work']));
        $this->assertTrue((new ScheduleRunCommand())->matches(['schedule:run']));
        $this->assertTrue((new ImpactCommand())->matches(['affected-files', 'x']));
        $this->assertTrue((new MigrateSpecsCommand())->matches(['migrate', 'specs', '--dry-run']));
        $this->assertTrue((new CodemodRunCommand())->matches(['codemod', 'run', 'feature-manifest-v1-to-v2', '--dry-run']));
    }
}
