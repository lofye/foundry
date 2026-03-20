<?php
declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\Compiler\Codemod\CodemodEngine;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\GraphCompiler;
use Foundry\Compiler\GraphVerifier;
use Foundry\Compiler\Migration\ManifestVersionResolver;
use Foundry\Compiler\Migration\DefinitionMigrator;
use Foundry\Documentation\GraphDocsGenerator;
use Foundry\Documentation\InspectUiGenerator;
use Foundry\Export\OpenApiExporter;
use Foundry\Generation\BillingGenerator;
use Foundry\Generation\ApiResourceGenerator;
use Foundry\Feature\FeatureLoader;
use Foundry\Generation\ContextManifestGenerator;
use Foundry\Generation\DeepTestGenerator;
use Foundry\Generation\FeatureGenerator;
use Foundry\Generation\FormSchemaRenderer;
use Foundry\Generation\AdminResourceGenerator;
use Foundry\Generation\IndexGenerator;
use Foundry\Generation\MigrationGenerator;
use Foundry\Generation\NotificationGenerator;
use Foundry\Generation\LocaleGenerator;
use Foundry\Generation\OrchestrationGenerator;
use Foundry\Generation\PolicyGenerator;
use Foundry\Generation\RolesGenerator;
use Foundry\Generation\ResourceGenerator;
use Foundry\Generation\SearchIndexGenerator;
use Foundry\Generation\StarterGenerator;
use Foundry\Generation\StreamGenerator;
use Foundry\Generation\TestGenerator;
use Foundry\Generation\UploadsGenerator;
use Foundry\Generation\WorkflowGenerator;
use Foundry\Notifications\NotificationPreviewer;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Paths;
use Foundry\Verification\ApiVerifier;
use Foundry\Verification\AuthVerifier;
use Foundry\Verification\BillingVerifier;
use Foundry\Verification\CacheVerifier;
use Foundry\Verification\ContractsVerifier;
use Foundry\Verification\EventsVerifier;
use Foundry\Verification\FeatureVerifier;
use Foundry\Verification\JobsVerifier;
use Foundry\Verification\LocalesVerifier;
use Foundry\Verification\MigrationsVerifier;
use Foundry\Verification\NotificationsVerifier;
use Foundry\Verification\OrchestrationsVerifier;
use Foundry\Verification\PoliciesVerifier;
use Foundry\Verification\ResourceVerifier;
use Foundry\Verification\SearchVerifier;
use Foundry\Verification\StreamsVerifier;
use Foundry\Verification\WorkflowVerifier;

final class CommandContext
{
    private ?Paths $paths = null;
    private ?FeatureLoader $loader = null;
    private ?ExtensionRegistry $extensions = null;
    private ?GraphCompiler $graphCompiler = null;
    private ?GraphVerifier $graphVerifier = null;
    private ?DefinitionMigrator $definitionMigrator = null;
    private ?CodemodEngine $codemodEngine = null;
    private ?ApiSurfaceRegistry $apiSurfaceRegistry = null;

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

    public function definitionMigrator(): DefinitionMigrator
    {
        return $this->definitionMigrator ??= new DefinitionMigrator(
            $this->paths(),
            new ManifestVersionResolver(),
            $this->extensionRegistry()->migrationRules(),
            $this->extensionRegistry()->definitionFormats(),
        );
    }

    public function codemodEngine(): CodemodEngine
    {
        return $this->codemodEngine ??= new CodemodEngine(
            $this->paths(),
            $this->extensionRegistry()->codemods(),
        );
    }

    public function apiSurfaceRegistry(): ApiSurfaceRegistry
    {
        return $this->apiSurfaceRegistry ??= new ApiSurfaceRegistry();
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

    public function deepTestGenerator(): DeepTestGenerator
    {
        return new DeepTestGenerator(
            $this->paths(),
            $this->graphCompiler(),
            $this->testGenerator(),
        );
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

    public function notificationGenerator(): NotificationGenerator
    {
        return new NotificationGenerator($this->paths());
    }

    public function billingGenerator(): BillingGenerator
    {
        return new BillingGenerator($this->paths(), $this->featureGenerator());
    }

    public function workflowGenerator(): WorkflowGenerator
    {
        return new WorkflowGenerator($this->paths(), $this->featureGenerator());
    }

    public function orchestrationGenerator(): OrchestrationGenerator
    {
        return new OrchestrationGenerator($this->paths(), $this->featureGenerator());
    }

    public function searchIndexGenerator(): SearchIndexGenerator
    {
        return new SearchIndexGenerator($this->paths());
    }

    public function streamGenerator(): StreamGenerator
    {
        return new StreamGenerator($this->paths(), $this->featureGenerator());
    }

    public function localeGenerator(): LocaleGenerator
    {
        return new LocaleGenerator($this->paths());
    }

    public function rolesGenerator(): RolesGenerator
    {
        return new RolesGenerator($this->paths());
    }

    public function policyGenerator(): PolicyGenerator
    {
        return new PolicyGenerator($this->paths());
    }

    public function apiResourceGenerator(): ApiResourceGenerator
    {
        return new ApiResourceGenerator($this->paths(), $this->resourceGenerator());
    }

    public function docsGenerator(): GraphDocsGenerator
    {
        return new GraphDocsGenerator($this->paths(), $this->apiSurfaceRegistry());
    }

    public function inspectUiGenerator(): InspectUiGenerator
    {
        return new InspectUiGenerator($this->paths());
    }

    public function openApiExporter(): OpenApiExporter
    {
        return new OpenApiExporter();
    }

    public function notificationPreviewer(): NotificationPreviewer
    {
        return new NotificationPreviewer($this->paths(), $this->graphCompiler());
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

    public function notificationsVerifier(): NotificationsVerifier
    {
        return new NotificationsVerifier($this->graphCompiler(), $this->paths());
    }

    public function apiVerifier(): ApiVerifier
    {
        return new ApiVerifier($this->graphCompiler());
    }

    public function billingVerifier(): BillingVerifier
    {
        return new BillingVerifier($this->graphCompiler());
    }

    public function workflowVerifier(): WorkflowVerifier
    {
        return new WorkflowVerifier($this->graphCompiler());
    }

    public function orchestrationsVerifier(): OrchestrationsVerifier
    {
        return new OrchestrationsVerifier($this->graphCompiler());
    }

    public function searchVerifier(): SearchVerifier
    {
        return new SearchVerifier($this->graphCompiler());
    }

    public function streamsVerifier(): StreamsVerifier
    {
        return new StreamsVerifier($this->graphCompiler());
    }

    public function localesVerifier(): LocalesVerifier
    {
        return new LocalesVerifier($this->graphCompiler(), $this->paths());
    }

    public function policiesVerifier(): PoliciesVerifier
    {
        return new PoliciesVerifier($this->graphCompiler());
    }
}
