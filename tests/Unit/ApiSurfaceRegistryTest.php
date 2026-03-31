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
        $explain = $registry->classifyCliCommand(['explain', 'publish_post']);
        $generatePrompt = $registry->classifyCliCommand(['generate', 'Add', 'bookmarks']);
        $licenseStatus = $registry->classifyCliCommand(['license', 'status']);
        $features = $registry->classifyCliCommand(['features']);
        $init = $registry->classifyCliCommand(['init']);
        $examplesList = $registry->classifyCliCommand(['examples:list']);
        $examplesLoad = $registry->classifyCliCommand(['examples:load', 'blog-api']);
        $packSearch = $registry->classifyCliCommand(['pack', 'search', 'blog']);
        $packList = $registry->classifyCliCommand(['pack', 'list']);
        $observeTrace = $registry->classifyCliCommand(['observe:trace', 'publish_post']);
        $observeProfile = $registry->classifyCliCommand(['observe:profile']);
        $observeCompare = $registry->classifyCliCommand(['observe:compare', 'run-a', 'run-b']);
        $history = $registry->classifyCliCommand(['history']);
        $regressions = $registry->classifyCliCommand(['regressions']);
        $inspectCliSurface = $registry->classifyCliCommand(['inspect', 'cli-surface']);
        $verifyCliSurface = $registry->classifyCliCommand(['verify', 'cli-surface']);
        $internal = $registry->classifyCliCommand(['queue:work']);

        $this->assertNotNull($stable);
        $this->assertNotNull($cacheInspect);
        $this->assertNotNull($scaffold);
        $this->assertNotNull($graphInspect);
        $this->assertNotNull($graphVisualize);
        $this->assertNotNull($graphExport);
        $this->assertNotNull($explain);
        $this->assertNotNull($generatePrompt);
        $this->assertNotNull($licenseStatus);
        $this->assertNotNull($features);
        $this->assertNotNull($init);
        $this->assertNotNull($examplesList);
        $this->assertNotNull($examplesLoad);
        $this->assertNotNull($packSearch);
        $this->assertNotNull($packList);
        $this->assertNotNull($observeTrace);
        $this->assertNotNull($observeProfile);
        $this->assertNotNull($observeCompare);
        $this->assertNotNull($history);
        $this->assertNotNull($regressions);
        $this->assertNotNull($inspectCliSurface);
        $this->assertNotNull($verifyCliSurface);
        $this->assertNotNull($internal);
        $this->assertSame('stable', $stable['stability']);
        $this->assertSame('Architecture', $stable['category']);
        $this->assertSame('compile', $stable['command_type']);
        $this->assertSame('stable', $cacheInspect['stability']);
        $this->assertSame('Build', $cacheInspect['category']);
        $this->assertSame('cache', $cacheInspect['command_type']);
        $this->assertSame('stable', $scaffold['stability']);
        $this->assertSame('new', $scaffold['signature']);
        $this->assertSame('App Scaffolding', $scaffold['category']);
        $this->assertSame('new', $scaffold['command_type']);
        $this->assertSame('stable', $graphInspect['stability']);
        $this->assertSame('Architecture', $graphInspect['category']);
        $this->assertSame('graph', $graphInspect['command_type']);
        $this->assertTrue($graphInspect['supports_pipeline_stage_filter']);
        $this->assertTrue($graphInspect['supports_extension_filter']);
        $this->assertSame('stable', $graphVisualize['stability']);
        $this->assertSame('stable', $graphExport['stability']);
        $this->assertSame('experimental', $explain['stability']);
        $this->assertSame('core', $explain['availability']);
        $this->assertStringContainsString('--neighbors', $explain['usage']);
        $this->assertStringContainsString('--git', $explain['usage']);
        $this->assertStringContainsString('--diff', $explain['usage']);
        $this->assertSame('Architecture', $explain['category']);
        $this->assertSame('explain', $explain['command_type']);
        $this->assertTrue($explain['supports_pipeline_stage_filter']);
        $this->assertStringContainsString('explain [<target>]', $explain['usage']);
        $this->assertSame('generate <intent>', $generatePrompt['signature']);
        $this->assertStringContainsString('--mode=<new|modify|repair>', $generatePrompt['usage']);
        $this->assertStringContainsString('--explain', $generatePrompt['usage']);
        $this->assertStringContainsString('--allow-dirty', $generatePrompt['usage']);
        $this->assertStringContainsString('--allow-pack-install', $generatePrompt['usage']);
        $this->assertStringContainsString('--git-commit', $generatePrompt['usage']);
        $this->assertSame('App Scaffolding', $generatePrompt['category']);
        $this->assertSame('generate', $generatePrompt['command_type']);
        $this->assertSame('stable', $init['stability']);
        $this->assertSame('App Scaffolding', $init['category']);
        $this->assertSame('init', $init['command_type']);
        $this->assertStringContainsString('--example=<blog-api|extensions-migrations>', $init['usage']);
        $this->assertSame('stable', $examplesList['stability']);
        $this->assertSame('App Scaffolding', $examplesList['category']);
        $this->assertSame('examples', $examplesList['command_type']);
        $this->assertStringContainsString('taxonomy', $examplesList['summary']);
        $this->assertSame('stable', $examplesLoad['stability']);
        $this->assertSame('App Scaffolding', $examplesLoad['category']);
        $this->assertSame('examples', $examplesLoad['command_type']);
        $this->assertStringContainsString('<blog-api|extensions-migrations>', $examplesLoad['usage']);
        $this->assertSame('Monetization', $licenseStatus['category']);
        $this->assertSame('license', $licenseStatus['command_type']);
        $this->assertSame('Monetization', $features['category']);
        $this->assertSame('features', $features['command_type']);
        $this->assertFalse($features['supports_pipeline_stage_filter']);
        $this->assertSame('experimental', $packSearch['stability']);
        $this->assertSame('Extensions', $packSearch['category']);
        $this->assertSame('pack', $packSearch['command_type']);
        $this->assertSame('experimental', $packList['stability']);
        $this->assertSame('Extensions', $packList['category']);
        $this->assertSame('pack', $packList['command_type']);
        $this->assertSame('experimental', $observeTrace['stability']);
        $this->assertSame('Observability', $observeTrace['category']);
        $this->assertSame('observe', $observeTrace['command_type']);
        $this->assertTrue($observeTrace['supports_pipeline_stage_filter']);
        $this->assertSame('experimental', $observeProfile['stability']);
        $this->assertSame('experimental', $observeCompare['stability']);
        $this->assertSame('experimental', $history['stability']);
        $this->assertSame('experimental', $regressions['stability']);
        $this->assertSame('stable', $inspectCliSurface['stability']);
        $this->assertSame('Reference', $inspectCliSurface['category']);
        $this->assertSame('inspect', $inspectCliSurface['command_type']);
        $this->assertSame('stable', $verifyCliSurface['stability']);
        $this->assertSame('Reference', $verifyCliSurface['category']);
        $this->assertSame('verify', $verifyCliSurface['command_type']);
        $this->assertSame('internal', $internal['stability']);
        $this->assertSame('Runtime', $internal['category']);
        $this->assertSame('queue', $internal['command_type']);
    }

    public function test_cli_command_signatures_are_unique(): void
    {
        $registry = new ApiSurfaceRegistry();
        $signatures = array_values(array_map(
            static fn(array $entry): string => (string) ($entry['signature'] ?? ''),
            $registry->cliCommands(),
        ));

        $this->assertSame($signatures, array_values(array_unique($signatures)));
    }

    public function test_classifies_configuration_and_generated_metadata_paths(): void
    {
        $registry = new ApiSurfaceRegistry();

        $manifest = $registry->classifyConfigurationArtifact('app/features/list_posts/feature.yaml');
        $platformConfig = $registry->classifyConfigurationArtifact('config/cache.php');
        $packRegistry = $registry->classifyConfigurationArtifact('.foundry/packs/installed.json');
        $generated = $registry->classifyGeneratedMetadata('app/generated/routes.php');
        $registryCache = $registry->classifyGeneratedMetadata('.foundry/cache/registry.json');
        $cacheMetadata = $registry->classifyGeneratedMetadata('app/.foundry/build/manifests/compile_cache.json');
        $qualityMetadata = $registry->classifyGeneratedMetadata('app/.foundry/build/quality/summary.json');
        $historyMetadata = $registry->classifyGeneratedMetadata('app/.foundry/build/history/build-abc.json');

        $this->assertNotNull($manifest);
        $this->assertNotNull($platformConfig);
        $this->assertNotNull($packRegistry);
        $this->assertNotNull($generated);
        $this->assertNotNull($registryCache);
        $this->assertNotNull($cacheMetadata);
        $this->assertNotNull($qualityMetadata);
        $this->assertNotNull($historyMetadata);
        $this->assertSame('public_api', $manifest['classification']);
        $this->assertSame('experimental_api', $platformConfig['classification']);
        $this->assertSame('extension_api', $packRegistry['classification']);
        $this->assertSame('internal_api', $generated['classification']);
        $this->assertSame('internal_api', $registryCache['classification']);
        $this->assertSame('internal_api', $cacheMetadata['classification']);
        $this->assertSame('internal_api', $qualityMetadata['classification']);
        $this->assertSame('internal_api', $historyMetadata['classification']);
    }
}
