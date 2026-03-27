<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Support\ApiSurfaceRegistry;
use PHPUnit\Framework\TestCase;

final class ApiSurfaceRegistryTest extends TestCase
{
    public function test_describe_returns_classification_metadata(): void
    {
        $registry = new ApiSurfaceRegistry();
        $description = $registry->describe();

        $this->assertSame(1, $description['schema_version']);
        $this->assertArrayHasKey('policy', $description);
        $this->assertArrayHasKey('php_namespace_rules', $description);
        $this->assertArrayHasKey('cli_commands', $description);
        $this->assertArrayHasKey('generated_metadata_formats', $description);
    }

    public function test_classifies_php_symbols_by_namespace_and_exact_extension_hook(): void
    {
        $registry = new ApiSurfaceRegistry();

        $public = $registry->classifyPhpSymbol('Foundry\\Feature\\FeatureAction');
        $extension = $registry->classifyPhpSymbol('Foundry\\Compiler\\Extensions\\CompilerExtension');
        $explainContributor = $registry->classifyPhpSymbol('Foundry\\Explain\\Contributors\\ExplainContributorInterface');
        $explainContribution = $registry->classifyPhpSymbol('Foundry\\Explain\\Contributors\\ExplainContribution');
        $internal = $registry->classifyPhpSymbol('Foundry\\Compiler\\Passes\\ValidatePass');
        $experimental = $registry->classifyPhpSymbol('Foundry\\AI\\AIProvider');

        $this->assertSame('public_api', $public['classification']);
        $this->assertSame('namespace_rule', $public['matched_by']);
        $this->assertSame('extension_api', $extension['classification']);
        $this->assertSame('exact_symbol', $extension['matched_by']);
        $this->assertSame('extension_api', $explainContributor['classification']);
        $this->assertSame('exact_symbol', $explainContributor['matched_by']);
        $this->assertSame('extension_api', $explainContribution['classification']);
        $this->assertSame('exact_symbol', $explainContribution['matched_by']);
        $this->assertSame('internal_api', $internal['classification']);
        $this->assertSame('experimental_api', $experimental['classification']);
        $this->assertSame('experimental', $experimental['stability']);
    }

    public function test_classifies_cli_commands_by_stability(): void
    {
        $registry = new ApiSurfaceRegistry();

        $stable = $registry->classifyCliCommand(['compile', 'graph']);
        $cacheInspect = $registry->classifyCliCommand(['cache', 'inspect']);
        $scaffold = $registry->classifyCliCommand(['new', 'demo-app']);
        $graphInspect = $registry->classifyCliCommand(['graph', 'inspect']);
        $graphVisualize = $registry->classifyCliCommand(['graph', 'visualize']);
        $graphExport = $registry->classifyCliCommand(['export', 'graph']);
        $proExplain = $registry->classifyCliCommand(['explain', 'publish_post']);
        $proGenerate = $registry->classifyCliCommand(['generate', 'Add', 'bookmarks']);
        $internal = $registry->classifyCliCommand(['queue:work']);

        $this->assertNotNull($stable);
        $this->assertNotNull($cacheInspect);
        $this->assertNotNull($scaffold);
        $this->assertNotNull($graphInspect);
        $this->assertNotNull($graphVisualize);
        $this->assertNotNull($graphExport);
        $this->assertNotNull($proExplain);
        $this->assertNotNull($proGenerate);
        $this->assertNotNull($internal);
        $this->assertSame('stable', $stable['stability']);
        $this->assertSame('stable', $cacheInspect['stability']);
        $this->assertSame('stable', $scaffold['stability']);
        $this->assertSame('new', $scaffold['signature']);
        $this->assertSame('stable', $graphInspect['stability']);
        $this->assertSame('stable', $graphVisualize['stability']);
        $this->assertSame('stable', $graphExport['stability']);
        $this->assertSame('experimental', $proExplain['stability']);
        $this->assertSame('pro', $proExplain['availability']);
        $this->assertStringContainsString('--neighbors', $proExplain['usage']);
        $this->assertSame('generate <prompt>', $proGenerate['signature']);
        $this->assertStringContainsString('--deterministic', $proGenerate['usage']);
        $this->assertStringContainsString('--provider=<name>', $proGenerate['usage']);
        $this->assertSame('internal', $internal['stability']);
    }

    public function test_classifies_configuration_and_generated_metadata_paths(): void
    {
        $registry = new ApiSurfaceRegistry();

        $manifest = $registry->classifyConfigurationArtifact('app/features/list_posts/feature.yaml');
        $platformConfig = $registry->classifyConfigurationArtifact('config/cache.php');
        $generated = $registry->classifyGeneratedMetadata('app/generated/routes.php');
        $cacheMetadata = $registry->classifyGeneratedMetadata('app/.foundry/build/manifests/compile_cache.json');

        $this->assertNotNull($manifest);
        $this->assertNotNull($platformConfig);
        $this->assertNotNull($generated);
        $this->assertNotNull($cacheMetadata);
        $this->assertSame('public_api', $manifest['classification']);
        $this->assertSame('experimental_api', $platformConfig['classification']);
        $this->assertSame('internal_api', $generated['classification']);
        $this->assertSame('internal_api', $cacheMetadata['classification']);
    }
}
