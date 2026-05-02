<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextDoctorDiagnosticRule;
use Foundry\Context\ContextDoctorDiagnosticRuleContext;
use Foundry\Context\ContextDoctorDiagnosticRuleResult;
use Foundry\Context\ContextDoctorDiagnosticTarget;
use Foundry\Context\ContextDoctorService;
use Foundry\Context\ContextInitService;
use Foundry\Context\ContextInspectionService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextInspectionServiceTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_aggregation_combines_doctor_and_alignment_results_correctly(): void
    {
        $this->initService()->init('event-bus');

        $result = $this->service()->inspectFeature('event-bus');

        $this->assertSame('event-bus', $result['feature']);
        $this->assertTrue($result['can_proceed']);
        $this->assertFalse($result['requires_repair']);
        $this->assertSame('ok', $result['summary']['doctor_status']);
        $this->assertSame('warning', $result['summary']['alignment_status']);
        $this->assertSame('ok', $result['doctor']['status']);
        $this->assertSame('warning', $result['alignment']['status']);
        $this->assertSame([
            'Update the feature state to reflect current implementation.',
        ], $result['required_actions']);
    }

    public function test_verification_mapping_produces_correct_pass_fail_outcomes(): void
    {
        $this->writeConsumableContext('pass-feature');
        $this->initService()->init('warning-feature');

        $pass = $this->service()->verifyFeature('pass-feature');
        $warning = $this->service()->verifyFeature('warning-feature');
        $fail = $this->service()->verifyFeature('missing-feature');

        $this->assertSame('pass', $pass['status']);
        $this->assertTrue($pass['can_proceed']);
        $this->assertFalse($pass['requires_repair']);
        $this->assertTrue($pass['consumable']);
        $this->assertSame('ok', $pass['doctor_status']);
        $this->assertSame('ok', $pass['alignment_status']);
        $this->assertSame([], $pass['required_actions']);

        $this->assertSame('pass', $warning['status']);
        $this->assertTrue($warning['can_proceed']);
        $this->assertFalse($warning['requires_repair']);
        $this->assertFalse($warning['consumable']);
        $this->assertSame('ok', $warning['doctor_status']);
        $this->assertSame('warning', $warning['alignment_status']);
        $this->assertSame([
            'Update the feature state to reflect current implementation.',
        ], $warning['required_actions']);

        $this->assertSame('fail', $fail['status']);
        $this->assertFalse($fail['can_proceed']);
        $this->assertTrue($fail['requires_repair']);
        $this->assertFalse($fail['consumable']);
        $this->assertSame('repairable', $fail['doctor_status']);
        $this->assertSame('mismatch', $fail['alignment_status']);
    }

    public function test_verify_all_returns_deterministic_feature_ordering(): void
    {
        $this->initService()->init('zeta-feature');
        $this->initService()->init('alpha-feature');

        $result = $this->service()->verifyAll();
        $features = array_values(array_map(
            static fn(array $feature): string => (string) ($feature['feature'] ?? ''),
            $result['features'],
        ));

        $this->assertSame('pass', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame(['alpha-feature', 'zeta-feature'], $features);
        $this->assertSame(2, $result['summary']['pass']);
        $this->assertSame(2, $result['summary']['total']);
        $this->assertSame([false, false], array_values(array_map(
            static fn(array $feature): bool => (bool) ($feature['consumable'] ?? true),
            $result['features'],
        )));
    }

    public function test_verify_feature_coalesces_duplicate_doctor_issues_and_actions(): void
    {
        $this->writeConsumableContext('pass-feature');

        $doctor = new ContextDoctorService(
            new Paths($this->project->root),
            diagnosticRules: [
                $this->fixedRule(
                    code: 'BETA_RULE',
                    message: 'Shared issue.',
                    targets: [new ContextDoctorDiagnosticTarget('spec', 'docs/features/pass-feature/pass-feature.spec.md')],
                    requiredActions: ['Shared action'],
                ),
                $this->fixedRule(
                    code: 'ALPHA_RULE',
                    message: 'Shared issue.',
                    targets: [new ContextDoctorDiagnosticTarget('spec', 'docs/features/pass-feature/pass-feature.spec.md')],
                    requiredActions: ['Shared action'],
                ),
            ],
        );

        $result = (new ContextInspectionService(new Paths($this->project->root), $doctor))->verifyFeature('pass-feature');

        $this->assertSame('fail', $result['status']);
        $this->assertSame([
            [
                'source' => 'doctor',
                'code' => 'ALPHA_RULE',
                'message' => 'Shared issue.',
                'file_path' => 'docs/features/pass-feature/pass-feature.spec.md',
            ],
        ], array_slice($result['issues'], 0, 1));
        $this->assertSame(['Shared action'], $result['required_actions']);
    }

    private function writeConsumableContext(string $feature): void
    {
        $this->initService()->init($feature);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.spec.md', <<<MD
# Feature Spec: {$feature}

## Purpose

Introduce deterministic event bus handling.

## Goals

- Preserve consumable canonical context.

## Non-Goals

- Do not add async delivery.

## Constraints

- Keep output deterministic.

## Expected Behavior

- Event bus feature scaffolding exists in the app.

## Acceptance Criteria

- Event bus feature files are present.

## Assumptions

- Initial implementation may be scaffold-first.
MD);

        file_put_contents($this->project->root . '/docs/features/' . $feature . '/' . $feature . '.md', <<<MD
# Feature: {$feature}

## Purpose

Introduce deterministic event bus handling.

## Current State

- Event bus feature scaffolding exists in the app.
- Event bus feature files are present.

## Open Questions

- None.

## Next Steps

- Preserve deterministic event bus verification coverage.
MD);
    }

    private function service(): ContextInspectionService
    {
        return new ContextInspectionService(new Paths($this->project->root));
    }

    /**
     * @param list<ContextDoctorDiagnosticTarget> $targets
     * @param list<string> $requiredActions
     */
    private function fixedRule(string $code, string $message, array $targets, array $requiredActions): ContextDoctorDiagnosticRule
    {
        return new class ($code, $message, $targets, $requiredActions) implements ContextDoctorDiagnosticRule {
            /**
             * @param list<ContextDoctorDiagnosticTarget> $targets
             * @param list<string> $requiredActions
             */
            public function __construct(
                private readonly string $code,
                private readonly string $message,
                private readonly array $targets,
                private readonly array $requiredActions,
            ) {}

            public function evaluate(ContextDoctorDiagnosticRuleContext $context): ?ContextDoctorDiagnosticRuleResult
            {
                return new ContextDoctorDiagnosticRuleResult(
                    code: $this->code,
                    message: $this->message,
                    targets: $this->targets,
                    requiredActions: $this->requiredActions,
                    requiresRepair: true,
                );
            }
        };
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }
}
