<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextDoctorDiagnosticRule;
use Foundry\Context\ContextDoctorDiagnosticRuleContext;
use Foundry\Context\ContextDoctorDiagnosticRuleResult;
use Foundry\Context\ContextDoctorDiagnosticTarget;
use Foundry\Context\ContextDoctorService;
use Foundry\Context\ContextInitService;
use Foundry\Context\ExecutionSpecDriftContextDoctorRule;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextDoctorDiagnosticRulesTest extends TestCase
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

    public function test_execution_spec_drift_rule_produces_normalized_result_for_missing_canonical_context(): void
    {
        $rule = new ExecutionSpecDriftContextDoctorRule();

        $result = $rule->evaluate(new ContextDoctorDiagnosticRuleContext(
            feature: 'event-bus',
            files: $this->missingFiles(),
            featureHasExecutionSpecs: true,
        ));

        $this->assertInstanceOf(ContextDoctorDiagnosticRuleResult::class, $result);
        $this->assertSame('EXECUTION_SPEC_DRIFT', $result->code);
        $this->assertSame(
            'Execution specs exist for this feature, but canonical feature context is missing or incomplete.',
            $result->message,
        );
        $this->assertSame(['spec', 'state', 'decisions'], $result->targetBuckets());
        $this->assertSame([
            'docs/features/event-bus.spec.md',
            'docs/features/event-bus.md',
            'docs/features/event-bus.decisions.md',
        ], $result->targetFilePaths());
        $this->assertSame([
            'Create or initialize the missing canonical feature context files for event-bus.',
            'Run foundry context init event-bus --json when appropriate to initialize missing canonical context files.',
            'Do not rely on execution specs as the source of truth for event-bus.',
        ], $result->requiredActions);
        $this->assertTrue($result->requiresRepair);
    }

    public function test_execution_spec_drift_rule_returns_null_when_condition_is_not_met(): void
    {
        $rule = new ExecutionSpecDriftContextDoctorRule();

        $this->assertNull($rule->evaluate(new ContextDoctorDiagnosticRuleContext(
            feature: 'event-bus',
            files: $this->missingFiles(),
            featureHasExecutionSpecs: false,
        )));

        $this->assertNull($rule->evaluate(new ContextDoctorDiagnosticRuleContext(
            feature: 'event-bus',
            files: $this->existingFiles(),
            featureHasExecutionSpecs: true,
        )));
    }

    public function test_doctor_service_supports_multiple_diagnostic_rules_deterministically(): void
    {
        $this->initService()->init('event-bus');

        $service = new ContextDoctorService(
            new Paths($this->project->root),
            diagnosticRules: [
                $this->fixedRule(
                    code: 'ZETA_RULE',
                    message: 'Later issue.',
                    targets: [new ContextDoctorDiagnosticTarget('spec', 'docs/features/event-bus.spec.md')],
                    requiredActions: ['Zeta action'],
                ),
                $this->fixedRule(
                    code: 'ALPHA_RULE',
                    message: 'Earlier issue.',
                    targets: [new ContextDoctorDiagnosticTarget('spec', 'docs/features/event-bus.spec.md')],
                    requiredActions: ['Alpha action'],
                ),
            ],
        );

        $result = $service->checkFeature('event-bus');

        $this->assertSame('repairable', $result['status']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_repair']);
        $this->assertSame(['ALPHA_RULE', 'ZETA_RULE'], $this->issueCodes((array) $result['files']['spec']['issues']));
        $this->assertSame([
            'Alpha action',
            'Zeta action',
        ], $result['required_actions']);
    }

    public function test_doctor_service_flattens_doctor_issues_for_verify_in_deterministic_order(): void
    {
        $this->initService()->init('event-bus');

        $service = new ContextDoctorService(
            new Paths($this->project->root),
            diagnosticRules: [
                $this->fixedRule(
                    code: 'ZETA_RULE',
                    message: 'State issue.',
                    targets: [new ContextDoctorDiagnosticTarget('state', 'docs/features/event-bus.md')],
                    requiredActions: ['Zeta action'],
                ),
                $this->fixedRule(
                    code: 'ALPHA_RULE',
                    message: 'Spec issue.',
                    targets: [new ContextDoctorDiagnosticTarget('spec', 'docs/features/event-bus.spec.md')],
                    requiredActions: ['Alpha action'],
                ),
            ],
        );

        $flattened = $service->flattenIssues($service->checkFeature('event-bus'));

        $this->assertSame([
            [
                'source' => 'doctor',
                'code' => 'ALPHA_RULE',
                'message' => 'Spec issue.',
                'file_path' => 'docs/features/event-bus.spec.md',
            ],
            [
                'source' => 'doctor',
                'code' => 'ZETA_RULE',
                'message' => 'State issue.',
                'file_path' => 'docs/features/event-bus.md',
            ],
        ], $flattened);
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function missingFiles(): array
    {
        return [
            'spec' => [
                'path' => 'docs/features/event-bus.spec.md',
                'exists' => false,
                'valid' => false,
                'missing_sections' => [],
                'issues' => [],
            ],
            'state' => [
                'path' => 'docs/features/event-bus.md',
                'exists' => false,
                'valid' => false,
                'missing_sections' => [],
                'issues' => [],
            ],
            'decisions' => [
                'path' => 'docs/features/event-bus.decisions.md',
                'exists' => false,
                'valid' => false,
                'issues' => [],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function existingFiles(): array
    {
        return [
            'spec' => [
                'path' => 'docs/features/event-bus.spec.md',
                'exists' => true,
                'valid' => true,
                'missing_sections' => [],
                'issues' => [],
            ],
            'state' => [
                'path' => 'docs/features/event-bus.md',
                'exists' => true,
                'valid' => true,
                'missing_sections' => [],
                'issues' => [],
            ],
            'decisions' => [
                'path' => 'docs/features/event-bus.decisions.md',
                'exists' => true,
                'valid' => true,
                'issues' => [],
            ],
        ];
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

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return list<string>
     */
    private function issueCodes(array $issues): array
    {
        return array_values(array_map(
            static fn(array $issue): string => (string) ($issue['code'] ?? ''),
            $issues,
        ));
    }
}
