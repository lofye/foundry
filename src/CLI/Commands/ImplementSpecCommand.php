<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextExecutionService;
use Foundry\Context\ExecutionSpec;
use Foundry\Context\ExecutionSpecFilename;
use Foundry\Context\ExecutionSpecResolver;
use Foundry\Context\FeatureNameValidator;
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

        $specId = $this->requestedSpecId($args);

        try {
            $executionSpec = $this->resolveExecutionSpec($args, $context);
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
     * @param array<int,string> $args
     */
    private function resolveExecutionSpec(array $args, CommandContext $context): ExecutionSpec
    {
        $positionals = $this->positionalArguments($args);
        $resolver = new ExecutionSpecResolver($context->paths());

        return match (count($positionals)) {
            0 => throw new FoundryError(
                'CLI_IMPLEMENT_SPEC_TARGET_REQUIRED',
                'validation',
                [],
                'Implement spec target required.',
            ),
            1 => $this->resolveSinglePositionalSpec($resolver, $positionals[0]),
            2 => $resolver->resolveWithinFeature($positionals[0], $positionals[1]),
            default => throw new FoundryError(
                'CLI_IMPLEMENT_SPEC_ARGUMENTS_INVALID',
                'validation',
                ['arguments' => $positionals],
                'Implement spec accepts either <feature>/<id>-<slug>, <id>-<slug>, or <feature> <id>.',
            ),
        };
    }

    private function resolveSinglePositionalSpec(ExecutionSpecResolver $resolver, string $argument): ExecutionSpec
    {
        $trimmed = trim($argument);
        $normalized = $this->stripMarkdownExtension($trimmed);

        if (
            !str_contains($trimmed, '/')
            && !str_starts_with($trimmed, 'docs/specs/')
            && !ExecutionSpecFilename::isCanonicalName($normalized)
            && (new FeatureNameValidator())->validate(FeatureNaming::canonical($normalized))->valid
        ) {
            throw new FoundryError(
                'CLI_IMPLEMENT_SPEC_ID_REQUIRED',
                'validation',
                ['feature' => FeatureNaming::canonical($normalized)],
                'Implement spec id required when invoking `implement spec <feature> <id>`.',
            );
        }

        return $resolver->resolve($trimmed);
    }

    /**
     * @param array<int,string> $args
     * @return list<string>
     */
    private function positionalArguments(array $args): array
    {
        return array_values(array_filter(
            array_slice($args, 2),
            static fn(string $arg): bool => !in_array($arg, ['--repair', '--auto-repair'], true),
        ));
    }

    /**
     * @param array<int,string> $args
     */
    private function requestedSpecId(array $args): string
    {
        $positionals = $this->positionalArguments($args);

        return match (count($positionals)) {
            0 => '',
            1 => (string) $positionals[0],
            default => FeatureNaming::canonical((string) $positionals[0]) . '/' . trim((string) $positionals[1]),
        };
    }

    private function stripMarkdownExtension(string $value): string
    {
        return str_ends_with($value, '.md')
            ? substr($value, 0, -strlen('.md'))
            : $value;
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
     *     required_actions:list<string>,
     *     quality_gate?:array<string,mixed>
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

        if (is_array($payload['quality_gate'] ?? null)) {
            $lines[] = 'Quality gate: ' . (((bool) ($payload['quality_gate']['passed'] ?? false)) ? 'passed' : 'failed');
            if (isset($payload['quality_gate']['coverage']['global_line_coverage'])) {
                $coverage = $payload['quality_gate']['coverage']['global_line_coverage'];
                if (is_float($coverage) || is_int($coverage)) {
                    $lines[] = sprintf('Global line coverage: %.2f%%', (float) $coverage);
                }
            }
        }

        if (is_string($payload['reason'] ?? null) && $payload['reason'] !== '') {
            $lines[] = 'Reason: ' . $payload['reason'];
        }

        if (is_string($payload['required_action'] ?? null) && $payload['required_action'] !== '') {
            $lines[] = 'Required action: ' . $payload['required_action'];
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
            'EXECUTION_SPEC_NOT_FOUND' => [isset($error->details['feature'], $error->details['id'])
                ? 'Create or promote an active execution spec under docs/specs/<feature>/<id>-<slug>.md, or use a valid active execution spec id for that feature.'
                : 'Create the execution spec under docs/specs/<feature>/<id>-<slug>.md or use a valid existing execution spec id.'],
            'EXECUTION_SPEC_FEATURE_NOT_FOUND' => ['Use a valid feature under docs/specs/<feature>/ before invoking implement spec.'],
            'EXECUTION_SPEC_DRAFT_ONLY' => ['Promote the draft execution spec to docs/specs/<feature>/<id>-<slug>.md before implementing it.'],
            'EXECUTION_SPEC_ID_INVALID' => ['Use an execution spec id with one or more dot-separated 3-digit segments, such as 018 or 015.001.'],
            'EXECUTION_SPEC_HEADING_NON_CANONICAL' => ['Make the first line match `# Execution Spec: <id>-<slug>` for this file.'],
            'EXECUTION_SPEC_FEATURE_SECTION_MISSING' => ['Add a ## Feature section naming the canonical feature.'],
            'EXECUTION_SPEC_FEATURE_MISMATCH' => ['Make the ## Feature section match the docs/specs/<feature>/ directory for this execution spec.'],
            'EXECUTION_SPEC_FEATURE_INVALID' => ['Use a lowercase kebab-case feature name in the execution spec ## Feature section.'],
            'EXECUTION_SPEC_PATH_NON_CANONICAL' => ['Use a canonical execution spec id in the form <feature>/<id>-<slug>, <id>-<slug>, or invoke the command as <feature> <id>.'],
            'CLI_IMPLEMENT_SPEC_TARGET_REQUIRED' => ['Use `implement spec <feature>/<id>-<slug>`, `implement spec <id>-<slug>`, or `implement spec <feature> <id>`.'],
            'CLI_IMPLEMENT_SPEC_ID_REQUIRED' => ['Provide the execution spec id as `implement spec <feature> <id>`.'],
            'CLI_IMPLEMENT_SPEC_ARGUMENTS_INVALID' => ['Use `implement spec <feature>/<id>-<slug>`, `implement spec <id>-<slug>`, or `implement spec <feature> <id>`.'],
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
