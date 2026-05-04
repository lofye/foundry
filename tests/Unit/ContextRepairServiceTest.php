<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextInitService;
use Foundry\Context\ContextRepairService;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextRepairServiceTest extends TestCase
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

    public function test_repair_returns_repaired_when_safe_normalization_makes_context_consumable(): void
    {
        $this->writeRepairableConsumableContext();

        $result = $this->service()->repairFeature('event-bus');
        $spec = (string) file_get_contents($this->project->root . '/docs/features/event-bus/event-bus.spec.md');
        $state = (string) file_get_contents($this->project->root . '/docs/features/event-bus/event-bus.md');

        $this->assertSame('repaired', $result['status']);
        $this->assertSame([
            'docs/features/event-bus/event-bus.spec.md',
            'docs/features/event-bus/event-bus.md',
        ], $result['files_changed']);
        $this->assertTrue($result['can_proceed']);
        $this->assertFalse($result['requires_manual_action']);
        $this->assertSame([], $result['issues_remaining']);
        $this->assertSame([
            'Normalized docs/features/event-bus/event-bus.spec.md',
            'Normalized docs/features/event-bus/event-bus.md',
        ], $result['issues_repaired']);
        $this->assertStringContainsString(<<<'MD'
## Goals

- Keep event bus scaffolding deterministic.

## Non-Goals
MD, $spec);
        $this->assertSame(1, substr_count($spec, "- Keep output deterministic.\n"));
        $this->assertStringContainsString(<<<'MD'
## Current State

- Event bus feature scaffolding exists in the app.
- Event bus feature files are present.

## Open Questions
MD, $state);
        $this->assertStringNotContainsString("- Event bus feature scaffolding exists in the app.\n\n## Current State", $state);
    }

    public function test_repair_returns_blocked_when_safe_repairs_apply_but_context_is_still_not_consumable(): void
    {
        $this->writeDivergentSemanticContext();

        $result = $this->service()->repairFeature('event-bus');
        $state = (string) file_get_contents($this->project->root . '/docs/features/event-bus/event-bus.md');

        $this->assertSame('blocked', $result['status']);
        $this->assertSame([
            'docs/features/event-bus/event-bus.spec.md',
            'docs/features/event-bus/event-bus.md',
        ], $result['files_changed']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_manual_action']);
        $this->assertContains(
            'doctor:STALE_COMPLETED_ITEMS_IN_NEXT_STEPS @ docs/features/event-bus/event-bus.md',
            $result['issues_repaired'],
        );
        $this->assertContains(
            'doctor:DECISION_MISSING_FOR_STATE_DIVERGENCE @ docs/features/event-bus/event-bus.decisions.md',
            $result['issues_remaining'],
        );
        $this->assertStringNotContainsString('## Next Steps' . "\n\n" . '- Publishes posts immediately in production.', $state);
    }

    public function test_repair_fails_closed_when_critical_context_inputs_are_missing(): void
    {
        $this->writeRepairableConsumableContext();
        unlink($this->project->root . '/docs/features/event-bus/event-bus.decisions.md');

        $result = $this->service()->repairFeature('event-bus');
        $spec = (string) file_get_contents($this->project->root . '/docs/features/event-bus/event-bus.spec.md');

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $result['files_changed']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_manual_action']);
        $this->assertSame('CONTEXT_REPAIR_CRITICAL_INPUT_MISSING', $result['error']['code']);
        $this->assertContains(
            'doctor:CONTEXT_FILE_MISSING @ docs/features/event-bus/event-bus.decisions.md',
            $result['issues_remaining'],
        );
        $this->assertSame(2, substr_count($spec, "- Keep output deterministic.\n"));
    }

    public function test_repair_returns_blocked_without_file_changes_when_context_requires_manual_alignment(): void
    {
        $this->initService()->init('event-bus');

        $result = $this->service()->repairFeature('event-bus');

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('docs/features/event-bus/event-bus.md', $result['files_changed']);
        $this->assertNotSame([], $result['issues_repaired']);
        $this->assertFalse($result['can_proceed']);
        $this->assertTrue($result['requires_manual_action']);
    }

    public function test_repair_fails_closed_when_existing_context_file_is_unreadable(): void
    {
        $this->initService()->init('event-bus');
        $decisionsPath = $this->project->root . '/docs/features/event-bus/event-bus.decisions.md';
        chmod($decisionsPath, 0000);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if ($severity === E_WARNING) {
                $warnings[] = $message;

                return true;
            }

            return false;
        });

        try {
            $result = $this->service()->repairFeature('event-bus');
        } finally {
            restore_error_handler();
            chmod($decisionsPath, 0644);
        }

        $this->assertSame('failed', $result['status']);
        $this->assertSame('CONTEXT_REPAIR_CRITICAL_INPUT_MISSING', $result['error']['code']);
        $this->assertNotSame([], $warnings);
        $this->assertContains(
            'doctor:CONTEXT_FILE_UNREADABLE @ docs/features/event-bus/event-bus.decisions.md',
            $result['issues_remaining'],
        );
    }

    private function service(): ContextRepairService
    {
        return new ContextRepairService(new Paths($this->project->root));
    }

    private function initService(): ContextInitService
    {
        return new ContextInitService(new Paths($this->project->root));
    }

    private function writeRepairableConsumableContext(): void
    {
        $this->initService()->init('event-bus');

        file_put_contents($this->project->root . '/docs/features/event-bus/event-bus.spec.md', <<<'MD'
# Feature Spec: event-bus

## Purpose

Introduce event bus handling.

## Constraints

- Keep output deterministic.
- Keep output deterministic.

## Goals

- Keep event bus scaffolding deterministic.

## Non-Goals

- Do not add async delivery.

## Expected Behavior

- Event bus feature scaffolding exists in the app.

## Acceptance Criteria

- Event bus feature files are present.

## Assumptions

- Initial implementation may be scaffold-first.
MD);

        file_put_contents($this->project->root . '/docs/features/event-bus/event-bus.md', <<<'MD'
# Feature: event-bus

## Purpose

Introduce event bus handling.

## Next Steps

- Add smoke-test coverage.

## Current State

- Event bus feature scaffolding exists in the app.
- Event bus feature files are present.

## Open Questions

- None.
MD);
    }

    private function writeDivergentSemanticContext(): void
    {
        $this->initService()->init('event-bus');

        file_put_contents($this->project->root . '/docs/features/event-bus/event-bus.spec.md', <<<'MD'
# Feature Spec: event-bus

## Purpose

Publish posts safely.

## Goals

- Keep publication deterministic.

## Non-Goals

- Do not bypass moderation silently.

## Constraints

- Preserve review workflow history.

## Expected Behavior

- Publishes blog posts through moderated review workflow.

## Acceptance Criteria

- Blog posts publish only after moderation review.

## Assumptions

- Moderation remains the default policy.
MD);

        file_put_contents($this->project->root . '/docs/features/event-bus/event-bus.md', <<<'MD'
# Feature: event-bus

## Purpose

Publish posts safely.

## Open Questions

- None.

## Next Steps

- Publishes posts immediately in production.

## Current State

- Publishes posts immediately in production.
MD);
    }
}
