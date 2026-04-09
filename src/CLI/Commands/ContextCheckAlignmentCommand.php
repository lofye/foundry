<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextInspectionService;
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

        $payload = (new ContextInspectionService($context->paths()))->alignmentForFeature($featureName);

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

    /**
     * @param array{status:string,feature:string,can_proceed:bool,requires_repair:bool,issues:list<array<string,mixed>>,required_actions:list<string>} $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            'Context alignment: ' . $payload['feature'],
            'Status: ' . $payload['status'],
            'Can proceed: ' . ($payload['can_proceed'] ? 'yes' : 'no'),
            'Requires repair: ' . ($payload['requires_repair'] ? 'yes' : 'no'),
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
