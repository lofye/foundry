<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\CLI\Commands\GenerateFeatureCommand;
use Foundry\CLI\Commands\GenerateIndexesCommand;
use Foundry\CLI\Commands\ImpactCommand;
use Foundry\CLI\Commands\InspectFeatureCommand;
use Foundry\CLI\Commands\InspectRouteCommand;
use Foundry\CLI\Commands\QueueWorkCommand;
use Foundry\CLI\Commands\ScheduleRunCommand;
use Foundry\CLI\Commands\ServeCommand;
use Foundry\CLI\Commands\VerifyContractsCommand;
use Foundry\CLI\Commands\VerifyFeatureCommand;
use PHPUnit\Framework\TestCase;

final class CLICommandMatchesTest extends TestCase
{
    public function test_matches_methods_cover_all_commands(): void
    {
        $this->assertTrue((new InspectFeatureCommand())->matches(['inspect', 'feature', 'x']));
        $this->assertFalse((new InspectFeatureCommand())->matches(['other']));

        $this->assertTrue((new InspectRouteCommand())->matches(['inspect', 'route', 'GET', '/']));
        $this->assertTrue((new GenerateFeatureCommand())->matches(['generate', 'feature', 'x.yaml']));
        $this->assertTrue((new GenerateIndexesCommand())->matches(['generate', 'indexes']));
        $this->assertTrue((new VerifyFeatureCommand())->matches(['verify', 'feature', 'x']));
        $this->assertTrue((new VerifyContractsCommand())->matches(['verify', 'contracts']));
        $this->assertTrue((new ServeCommand())->matches(['serve']));
        $this->assertTrue((new QueueWorkCommand())->matches(['queue:work']));
        $this->assertTrue((new ScheduleRunCommand())->matches(['schedule:run']));
        $this->assertTrue((new ImpactCommand())->matches(['affected-files', 'x']));
    }
}
