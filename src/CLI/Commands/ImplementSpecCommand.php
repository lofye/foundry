<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextExecutionService;
use Foundry\Context\ExecutionSpecResolver;
use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;

final class ImplementSpecCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['implement spec'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'implement' && ($args[1] ?? null) === 'spec';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $specId = (string) ($args[2] ?? '');
        if ($specId === '') {
            throw new FoundryError(
                'CLI_IMPLEMENT_SPEC_REQUIRED',
                'validation',
                [],
                'Implement spec id required.',
            );
        }

        $repair = in_array('--repair', $args, true);
        $autoRepair = in_array('--auto-repair', $args, true);
        if ($repair && $autoRepair) {
            throw new FoundryError(
                'CLI_IMPLEMENT_REPAIR_MODE_CONFLICT',
                'validation',
                ['repair' => true, 'auto_repair' => true],
                'Use either --repair or --auto-repair, not both.',
            );
        }

        try {
            $executionSpec = (new ExecutionSpecResolver($context->paths()))->resolve($specId);
            $payload = (new ContextExecutionService($context->paths()))
                ->executeSpec($executionSpec, repair: $repair, autoRepair: $autoRepair);
        } catch (FoundryError $error) {
            $payload = $this->blockedPayloadFromError($specId, $error);
        }

        $status = (string) ($payload['status'] ?? 'blocked');

        return [
            'status' => in_array($status, ['completed', 'repaired'], true) ? 0 : 1,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array{
     *     spec_id:string,
     *     feature:string,
     *     status:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     repair_attempted:bool,
     *     repair_successful:bool,
     *     actions_taken:list<string>,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * } $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            'Implement spec: ' . $payload['spec_id'],
            'Feature: ' . $payload['feature'],
            'Status: ' . $payload['status'],
            'Can proceed: ' . ($payload['can_proceed'] ? 'yes' : 'no'),
            'Requires repair: ' . ($payload['requires_repair'] ? 'yes' : 'no'),
            'Repair attempted: ' . ($payload['repair_attempted'] ? 'yes' : 'no'),
            'Repair successful: ' . ($payload['repair_successful'] ? 'yes' : 'no'),
            'Actions taken:',
        ];

        if ($payload['actions_taken'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($payload['actions_taken'] as $action) {
                $lines[] = '- ' . $action;
            }
        }

        $lines[] = 'Issues:';
        if ($payload['issues'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($payload['issues'] as $issue) {
                $lines[] = '- ' . (string) ($issue['code'] ?? '') . ': ' . (string) ($issue['message'] ?? '');
            }
        }

        $lines[] = 'Required actions:';
        if ($payload['required_actions'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($payload['required_actions'] as $action) {
                $lines[] = '- ' . $action;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return array{
     *     spec_id:string,
     *     feature:string,
     *     status:string,
     *     can_proceed:bool,
     *     requires_repair:bool,
     *     repair_attempted:bool,
     *     repair_successful:bool,
     *     actions_taken:list<string>,
     *     issues:list<array<string,mixed>>,
     *     required_actions:list<string>
     * }
     */
    private function blockedPayloadFromError(string $specId, FoundryError $error): array
    {
        $feature = '';
        if (is_string($error->details['feature'] ?? null)) {
            $feature = FeatureNaming::canonical((string) $error->details['feature']);
        } elseif (preg_match('#^(?:docs/specs/)?([a-z0-9]+(?:-[a-z0-9]+)*)/#', $specId, $matches) === 1) {
            $feature = FeatureNaming::canonical((string) $matches[1]);
        }

        $requiredActions = match ($error->errorCode) {
            'EXECUTION_SPEC_AMBIGUOUS' => array_values(array_map(
                static fn(string $match): string => 'Use a fully qualified execution spec id: ' . preg_replace('#^docs/specs/|\.md$#', '', $match),
                (array) ($error->details['matches'] ?? []),
            )),
            'EXECUTION_SPEC_NOT_FOUND' => ['Create the execution spec under docs/specs/<feature>/<NNN-name>.md or use a valid existing execution spec id.'],
            'EXECUTION_SPEC_FEATURE_SECTION_MISSING' => ['Add a ## Feature section naming the canonical feature.'],
            'EXECUTION_SPEC_FEATURE_MISMATCH' => ['Make the ## Feature section match the docs/specs/<feature>/ directory for this execution spec.'],
            'EXECUTION_SPEC_FEATURE_INVALID' => ['Use a lowercase kebab-case feature name in the execution spec ## Feature section.'],
            'EXECUTION_SPEC_PATH_NON_CANONICAL' => ['Use a canonical execution spec id in the form <feature>/<NNN-name>.'],
            default => [$error->getMessage() !== '' ? $error->getMessage() : 'Resolve the execution spec issue before rerunning implement spec.'],
        };

        return [
            'spec_id' => $specId,
            'feature' => $feature,
            'status' => 'blocked',
            'can_proceed' => false,
            'requires_repair' => true,
            'repair_attempted' => false,
            'repair_successful' => false,
            'actions_taken' => [],
            'issues' => [[
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
                'file_path' => (string) ($error->details['path'] ?? ''),
            ]],
            'required_actions' => $requiredActions,
        ];
    }
}
