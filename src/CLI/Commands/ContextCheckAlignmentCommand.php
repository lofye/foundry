<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\AlignmentChecker;
use Foundry\Context\AlignmentIssue;
use Foundry\Context\AlignmentResult;
use Foundry\Context\ContextDoctorService;
use Foundry\Context\ContextFileResolver;
use Foundry\Support\FoundryError;

final class ContextCheckAlignmentCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['context check-alignment'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'context' && ($args[1] ?? null) === 'check-alignment';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        if (in_array('--all', $args, true)) {
            throw new FoundryError(
                'CLI_CONTEXT_ALIGNMENT_ALL_UNSUPPORTED',
                'validation',
                [],
                'Context check-alignment requires --feature=<feature>.',
            );
        }

        $featureName = $this->extractOption($args, '--feature');
        if ($featureName === null || $featureName === '') {
            throw new FoundryError(
                'CLI_CONTEXT_ALIGNMENT_FEATURE_REQUIRED',
                'validation',
                [],
                'Context check-alignment requires --feature=<feature>.',
            );
        }

        $doctorPayload = (new ContextDoctorService($context->paths()))->checkFeature($featureName);
        $doctorStatus = (string) ($doctorPayload['status'] ?? 'repairable');

        if (!in_array($doctorStatus, ['ok', 'warning'], true)) {
            $payload = $this->preflightFailurePayload($featureName, $doctorPayload);
        } else {
            $resolver = new ContextFileResolver();
            $checker = new AlignmentChecker();

            $payload = $checker->check(
                $this->readFile($context, $resolver->specPath($featureName)),
                $this->readFile($context, $resolver->statePath($featureName)),
                $this->readFile($context, $resolver->decisionsPath($featureName)),
            )->toArray($featureName);
        }

        return [
            'status' => ((string) ($payload['status'] ?? 'warning')) === 'mismatch' ? 1 : 0,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function extractOption(array $args, string $name): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $name . '=')) {
                $value = substr($arg, strlen($name . '='));

                return $value !== '' ? $value : null;
            }

            if ($arg === $name) {
                $value = (string) ($args[$index + 1] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private function readFile(CommandContext $context, string $relativePath): string
    {
        $contents = file_get_contents($context->paths()->join($relativePath));
        if ($contents === false) {
            throw new FoundryError(
                'CLI_CONTEXT_ALIGNMENT_FILE_UNREADABLE',
                'filesystem',
                ['path' => $relativePath],
                'Context file could not be read for alignment.',
            );
        }

        return $contents;
    }

    /**
     * @param array<string,mixed> $doctorPayload
     * @return array{status:string,feature:string,issues:list<array<string,mixed>>,required_actions:list<string>}
     */
    private function preflightFailurePayload(string $featureName, array $doctorPayload): array
    {
        $requiredActions = array_values(array_map(
            'strval',
            (array) ($doctorPayload['required_actions'] ?? []),
        ));

        $message = $requiredActions === ['Use a lowercase kebab-case feature name.']
            ? 'Feature name must be lowercase kebab-case before alignment can be checked.'
            : 'Context files must be structurally valid before alignment can be checked.';

        return (new AlignmentResult(
            status: 'mismatch',
            issues: [
                new AlignmentIssue(
                    code: 'mismatch',
                    message: $message,
                    spec_section: null,
                    state_section: null,
                    decision_reference_found: false,
                ),
            ],
            required_actions: $requiredActions === [] ? ['Repair the feature context files before checking alignment.'] : $requiredActions,
        ))->toArray($featureName);
    }

    /**
     * @param array{status:string,feature:string,issues:list<array<string,mixed>>,required_actions:list<string>} $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            'Context alignment: ' . $payload['feature'],
            'Status: ' . $payload['status'],
            'Issues:',
        ];

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
}
