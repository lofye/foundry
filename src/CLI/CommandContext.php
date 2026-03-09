<?php
declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\Compiler\Codemod\CodemodEngine;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\GraphVerifier;
use Foundry\Compiler\Migration\ManifestVersionResolver;
use Foundry\Compiler\Migration\SpecMigrator;
use Foundry\Feature\FeatureLoader;
use Foundry\Generation\ContextManifestGenerator;
use Foundry\Generation\FeatureGenerator;
use Foundry\Generation\FormSchemaRenderer;
use Foundry\Generation\AdminResourceGenerator;
use Foundry\Generation\IndexGenerator;
use Foundry\Generation\MigrationGenerator;
use Foundry\Generation\ResourceGenerator;
use Foundry\Generation\StarterGenerator;
use Foundry\Generation\TestGenerator;
use Foundry\Generation\UploadsGenerator;
use Foundry\Support\Paths;
use Foundry\Verification\AuthVerifier;
use Foundry\Verification\CacheVerifier;
use Foundry\Verification\ContractsVerifier;
use Foundry\Verification\EventsVerifier;
use Foundry\Verification\FeatureVerifier;
use Foundry\Verification\JobsVerifier;
use Foundry\Verification\MigrationsVerifier;
use Foundry\Verification\ResourceVerifier;

final class CommandContext
{
    private ?Paths $paths = null;
    private ?FeatureLoader $loader = null;
    private ?ExtensionRegistry $extensions = null;
    private ?GraphCompiler $graphCompiler = null;
    private ?GraphVerifier $graphVerifier = null;
    private ?SpecMigrator $specMigrator = null;
    private ?CodemodEngine $codemodEngine = null;

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

    public function extensionRegistry(): ExtensionRegistry
    {
        return $this->extensions ??= ExtensionRegistry::forPaths($this->paths());
    }

    public function graphCompiler(): GraphCompiler
    {
        return $this->graphCompiler ??= new GraphCompiler($this->paths(), $this->extensionRegistry());
    }

    public function graphVerifier(): GraphVerifier
    {
        return $this->graphVerifier ??= new GraphVerifier($this->paths(), $this->graphCompiler()->buildLayout());
    }

    public function specMigrator(): SpecMigrator
    {
        return $this->specMigrator ??= new SpecMigrator(
            $this->paths(),
            new ManifestVersionResolver(),
            $this->extensionRegistry()->migrationRules(),
            $this->extensionRegistry()->specFormats(),
        );
    }

    public function codemodEngine(): CodemodEngine
    {
        return $this->codemodEngine ??= new CodemodEngine(
            $this->paths(),
            $this->extensionRegistry()->codemods(),
        );
    }

    public function indexGenerator(): IndexGenerator
    {
        return new IndexGenerator($this->paths(), $this->graphCompiler());
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

    public function starterGenerator(): StarterGenerator
    {
        return new StarterGenerator($this->paths(), $this->featureGenerator());
    }

    public function resourceGenerator(): ResourceGenerator
    {
        return new ResourceGenerator($this->paths(), $this->featureGenerator(), new FormSchemaRenderer());
    }

    public function adminResourceGenerator(): AdminResourceGenerator
    {
        return new AdminResourceGenerator($this->paths(), $this->featureGenerator());
    }

    public function uploadsGenerator(): UploadsGenerator
    {
        return new UploadsGenerator($this->paths(), $this->featureGenerator());
    }

    public function featureVerifier(): FeatureVerifier
    {
        return new FeatureVerifier($this->paths());
    }

    public function resourceVerifier(): ResourceVerifier
    {
        return new ResourceVerifier($this->graphCompiler());
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
