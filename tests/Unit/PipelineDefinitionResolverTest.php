<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Pipeline\PipelineDefinitionResolver;
use Foundry\Pipeline\PipelineStageDefinition;
use PHPUnit\Framework\TestCase;

final class PipelineDefinitionResolverTest extends TestCase
{
    public function test_resolves_default_and_extension_stages_deterministically(): void
    {
        $resolver = new PipelineDefinitionResolver();
        $diagnostics = new DiagnosticBag();

        $resolved = $resolver->resolve([
            new PipelineStageDefinition(
                name: 'tenant_resolution',
                afterStage: 'before_auth',
                priority: 20,
                extension: 'tenant.pack',
            ),
            new PipelineStageDefinition(
                name: 'response_headers',
                beforeStage: 'response_send',
                priority: 80,
                extension: 'http.pack',
            ),
        ], $diagnostics);

        $order = array_values(array_map('strval', (array) ($resolved['ordered_stages'] ?? [])));
        $this->assertContains('tenant_resolution', $order);
        $this->assertContains('response_headers', $order);
        $this->assertGreaterThan(array_search('before_auth', $order, true), array_search('tenant_resolution', $order, true));
        $this->assertLessThan(array_search('response_send', $order, true), array_search('response_headers', $order, true));
        $this->assertSame(0, $diagnostics->summary()['error']);
    }

    public function test_emits_diagnostics_for_unknown_constraints(): void
    {
        $resolver = new PipelineDefinitionResolver();
        $diagnostics = new DiagnosticBag();

        $resolver->resolve([
            new PipelineStageDefinition(
                name: 'bad_stage',
                afterStage: 'missing_stage',
                priority: 99,
                extension: 'bad.ext',
            ),
        ], $diagnostics);

        $codes = array_values(array_map(
            static fn (array $row): string => (string) ($row['code'] ?? ''),
            $diagnostics->toArray(),
        ));

        $this->assertContains('FDY8002_INTERCEPTOR_STAGE_CONFLICT', $codes);
    }
}
