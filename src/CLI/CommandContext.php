<?php
declare(strict_types=1);

namespace Forge\CLI;

use Forge\Feature\FeatureLoader;
use Forge\Generation\ContextManifestGenerator;
use Forge\Generation\FeatureGenerator;
use Forge\Generation\IndexGenerator;
use Forge\Generation\MigrationGenerator;
use Forge\Generation\TestGenerator;
use Forge\Support\Paths;
use Forge\Verification\AuthVerifier;
use Forge\Verification\CacheVerifier;
use Forge\Verification\ContractsVerifier;
use Forge\Verification\EventsVerifier;
use Forge\Verification\FeatureVerifier;
use Forge\Verification\JobsVerifier;
use Forge\Verification\MigrationsVerifier;

final class CommandContext
{
    private ?Paths $paths = null;
    private ?FeatureLoader $loader = null;

    public function __construct(private readonly ?string $cwd = null)
    {
    }

    public function paths(): Paths
    {
        return $this->paths ??= Paths::fromCwd($this->cwd);
    }

    public function featureLoader(): FeatureLoader
    {
        return $this->loader ??= new FeatureLoader($this->paths());
    }

    public function indexGenerator(): IndexGenerator
    {
        return new IndexGenerator($this->paths());
    }

    public function featureGenerator(): FeatureGenerator
    {
        return new FeatureGenerator($this->paths());
    }

    public function testGenerator(): TestGenerator
    {
        return new TestGenerator();
    }

    public function migrationGenerator(): MigrationGenerator
    {
        return new MigrationGenerator();
    }

    public function contextGenerator(): ContextManifestGenerator
    {
        return new ContextManifestGenerator($this->paths());
    }

    public function featureVerifier(): FeatureVerifier
    {
        return new FeatureVerifier($this->paths());
    }

    public function contractsVerifier(): ContractsVerifier
    {
        return new ContractsVerifier($this->paths());
    }

    public function authVerifier(): AuthVerifier
    {
        return new AuthVerifier($this->paths());
    }

    public function cacheVerifier(): CacheVerifier
    {
        return new CacheVerifier($this->paths());
    }

    public function eventsVerifier(): EventsVerifier
    {
        return new EventsVerifier($this->paths());
    }

    public function jobsVerifier(): JobsVerifier
    {
        return new JobsVerifier($this->paths());
    }

    public function migrationsVerifier(): MigrationsVerifier
    {
        return new MigrationsVerifier($this->paths());
    }
}
