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
        $internal = $registry->classifyPhpSymbol('Foundry\\Compiler\\Passes\\ValidatePass');
        $experimental = $registry->classifyPhpSymbol('Foundry\\AI\\AIProvider');

        $this->assertSame('public_api', $public['classification']);
        $this->assertSame('namespace_rule', $public['matched_by']);
        $this->assertSame('extension_api', $extension['classification']);
        $this->assertSame('exact_symbol', $extension['matched_by']);
        $this->assertSame('internal_api', $internal['classification']);
        $this->assertSame('experimental_api', $experimental['classification']);
        $this->assertSame('experimental', $experimental['stability']);
    }

    public function test_classifies_cli_commands_by_stability(): void
    {
        $registry = new ApiSurfaceRegistry();

        $stable = $registry->classifyCliCommand(['compile', 'graph']);
        $scaffold = $registry->classifyCliCommand(['new', 'demo-app']);
        $experimental = $registry->classifyCliCommand(['graph', 'visualize']);
        $internal = $registry->classifyCliCommand(['queue:work']);

        $this->assertNotNull($stable);
        $this->assertNotNull($scaffold);
        $this->assertNotNull($experimental);
        $this->assertNotNull($internal);
        $this->assertSame('stable', $stable['stability']);
        $this->assertSame('stable', $scaffold['stability']);
        $this->assertSame('new', $scaffold['signature']);
        $this->assertSame('experimental', $experimental['stability']);
        $this->assertSame('internal', $internal['stability']);
    }

    public function test_classifies_configuration_and_generated_metadata_paths(): void
    {
        $registry = new ApiSurfaceRegistry();

        $manifest = $registry->classifyConfigurationArtifact('app/features/list_posts/feature.yaml');
        $platformConfig = $registry->classifyConfigurationArtifact('app/platform/config/cache.php');
        $generated = $registry->classifyGeneratedMetadata('app/generated/routes.php');

        $this->assertNotNull($manifest);
        $this->assertNotNull($platformConfig);
        $this->assertNotNull($generated);
        $this->assertSame('public_api', $manifest['classification']);
        $this->assertSame('experimental_api', $platformConfig['classification']);
        $this->assertSame('internal_api', $generated['classification']);
    }
}
