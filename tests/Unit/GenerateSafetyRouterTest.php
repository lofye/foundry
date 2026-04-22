<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GenerateSafetyRouter;
use Foundry\Generate\GenerationPlan;
use Foundry\Generate\Intent;
use PHPUnit\Framework\TestCase;

final class GenerateSafetyRouterTest extends TestCase
{
    public function test_low_risk_additive_plan_routes_to_non_interactive(): void
    {
        $result = $this->router()->route(
            new Intent(raw: 'Create comments', mode: 'new'),
            $this->plan(
                actions: [['type' => 'create_file', 'path' => 'app/features/comments/feature.yaml', 'summary' => 'Create feature scaffold.']],
                confidence: ['band' => 'high', 'score' => 0.91],
            ),
        );

        $this->assertSame('non_interactive', $result['recommended_mode']);
        $this->assertFalse($result['recommended_interactive']);
        $this->assertSame(['low_risk_additive'], $result['reason_codes']);
    }

    public function test_medium_risk_plan_routes_to_interactive(): void
    {
        $result = $this->router()->route(
            new Intent(raw: 'Refine comments', mode: 'modify', target: 'comments'),
            $this->plan(
                actions: [['type' => 'update_file', 'path' => 'app/features/comments/prompts.md', 'summary' => 'Update prompts.']],
                confidence: ['band' => 'high', 'score' => 0.88],
            ),
        );

        $this->assertSame('interactive', $result['recommended_mode']);
        $this->assertTrue($result['recommended_interactive']);
        $this->assertSame(['elevated_risk'], $result['reason_codes']);
        $this->assertSame('MEDIUM', $result['signals']['risk_level']);
    }

    public function test_low_confidence_non_new_plan_routes_to_interactive(): void
    {
        $result = $this->router()->route(
            new Intent(raw: 'Repair comments', mode: 'repair', target: 'comments'),
            $this->plan(
                actions: [['type' => 'create_file', 'path' => 'app/features/comments/feature.yaml', 'summary' => 'Create feature scaffold.']],
                confidence: ['band' => 'low', 'score' => 0.44],
            ),
        );

        $this->assertSame('interactive', $result['recommended_mode']);
        $this->assertSame(['low_plan_confidence'], $result['reason_codes']);
    }

    public function test_explicit_interactive_flag_overrides_automatic_routing(): void
    {
        $result = $this->router()->route(
            new Intent(raw: 'Create comments', mode: 'new', interactive: true),
            $this->plan(
                actions: [['type' => 'create_file', 'path' => 'app/features/comments/feature.yaml', 'summary' => 'Create feature scaffold.']],
                confidence: ['band' => 'high', 'score' => 0.91],
            ),
        );

        $this->assertSame('interactive', $result['recommended_mode']);
        $this->assertTrue($result['forced_by_user']);
        $this->assertSame(['explicit_interactive'], $result['reason_codes']);
    }

    public function test_ci_context_prefers_non_interactive_routing(): void
    {
        $router = new GenerateSafetyRouter(ciDetector: static fn(): bool => true);

        $result = $router->route(
            new Intent(raw: 'Refine comments', mode: 'modify', target: 'comments'),
            $this->plan(
                actions: [['type' => 'update_file', 'path' => 'app/features/comments/prompts.md', 'summary' => 'Update prompts.']],
                confidence: ['band' => 'high', 'score' => 0.88],
            ),
        );

        $this->assertSame('non_interactive', $result['recommended_mode']);
        $this->assertSame(['ci_context'], $result['reason_codes']);
        $this->assertTrue($result['signals']['ci']);
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @param array<string,mixed> $confidence
     */
    private function plan(array $actions, array $confidence): GenerationPlan
    {
        return new GenerationPlan(
            actions: $actions,
            affectedFiles: array_values(array_map(
                static fn(array $action): string => (string) ($action['path'] ?? ''),
                $actions,
            )),
            risks: [],
            validations: ['compile_graph', 'verify_graph', 'verify_contracts'],
            origin: 'core',
            generatorId: 'core.feature',
            confidence: $confidence,
        );
    }

    private function router(): GenerateSafetyRouter
    {
        return new GenerateSafetyRouter();
    }
}
