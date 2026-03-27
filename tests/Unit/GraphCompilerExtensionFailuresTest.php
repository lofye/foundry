<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\CompilationState;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Extensions\AbstractCompilerExtension;
use Foundry\Compiler\Extensions\CoreCompilerExtension;
use Foundry\Compiler\Extensions\ExtensionDescriptor;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Compiler\GraphCompiler;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GraphCompilerExtensionFailuresTest extends TestCase
{
    public function test_compile_reports_extension_graph_integration_failures_without_aborting(): void
    {
        $project = new TempProject();

        try {
            $extension = new class extends AbstractCompilerExtension {
                public function name(): string
                {
                    return 'broken-ext';
                }
                public function version(): string
                {
                    return '1.0.0';
                }
                public function descriptor(): ExtensionDescriptor
                {
                    return new ExtensionDescriptor(
                        name: $this->name(),
                        version: $this->version(),
                        frameworkVersionConstraint: '^1',
                        graphVersionConstraint: '^1',
                    );
                }
                public function enrichPasses(): array
                {
                    return [
                        new class implements CompilerPass {
                            public function name(): string
                            {
                                return 'broken.enrich';
                            }
                            public function run(CompilationState $state): void
                            {
                                throw new \RuntimeException('boom');
                            }
                        },
                    ];
                }
            };

            $compiler = new GraphCompiler(
                Paths::fromCwd($project->root),
                new ExtensionRegistry([new CoreCompilerExtension(), $extension]),
            );

            $result = $compiler->compile();
            $codes = array_values(array_map(
                static fn(array $row): string => (string) ($row['code'] ?? ''),
                $result->diagnostics->toArray(),
            ));

            $this->assertContains('FDY7020_EXTENSION_GRAPH_INTEGRATION_FAILED', $codes);
            $this->assertNotNull($result->graph);
        } finally {
            $project->cleanup();
        }
    }
}
