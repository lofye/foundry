<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ExecutionSpecValidationService;

final class SpecValidateCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['spec:validate'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'spec:validate';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $requirePlans = in_array('--require-plans', $args, true);
        $payload = (new ExecutionSpecValidationService($context->paths()))->validate($requirePlans);

        return [
            'status' => $payload['ok'] ? 0 : 1,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array{
     *     ok:bool,
     *     summary:array{checked_files:int,features:int,violations:int},
     *     violations:list<array<string,mixed>>
     * } $payload
     */
    private function renderMessage(array $payload): string
    {
        if ($payload['ok']) {
            return implode(PHP_EOL, [
                'Spec validation passed',
                '',
                'Checked files: ' . $payload['summary']['checked_files'],
                'Violations: 0',
            ]);
        }

        $lines = [
            'Spec validation failed',
            '',
            'Violations:',
        ];

        foreach ($payload['violations'] as $violation) {
            $lines[] = $this->renderViolation($violation);
        }

        $lines[] = '';
        $lines[] = 'Summary:';
        $lines[] = '- Checked files: ' . $payload['summary']['checked_files'];
        $lines[] = '- Violations: ' . $payload['summary']['violations'];

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $violation
     */
    private function renderViolation(array $violation): string
    {
        $details = $this->renderDetails($violation);

        return '- ' . (string) ($violation['code'] ?? 'UNKNOWN')
            . ': ' . (string) ($violation['file_path'] ?? '')
            . ' - ' . (string) ($violation['message'] ?? '')
            . ($details === '' ? '' : ' (' . $details . ')');
    }

    /**
     * @param array<string,mixed> $violation
     */
    private function renderDetails(array $violation): string
    {
        $details = $violation['details'] ?? null;
        if (!is_array($details) || $details === []) {
            return '';
        }

        $parts = [];

        foreach (['feature', 'location', 'parent_id', 'id', 'field', 'line', 'expected_heading', 'actual_heading', 'missing_id', 'expected_missing_id', 'next_observed_id', 'plan_path'] as $key) {
            if (!array_key_exists($key, $details)) {
                continue;
            }

            $parts[] = $key . '=' . (string) $details[$key];
        }

        $paths = $details['paths'] ?? null;
        if (is_array($paths) && $paths !== []) {
            $parts[] = 'paths=' . implode(', ', array_map('strval', $paths));
        }

        return implode('; ', $parts);
    }
}
