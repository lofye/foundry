<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextRepairService;
use Foundry\Support\FoundryError;

final class ContextRepairCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['context repair'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'context' && ($args[1] ?? null) === 'repair';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $featureName = $this->extractOption($args, '--feature');
        if ($featureName === null || $featureName === '') {
            throw new FoundryError(
                'CLI_CONTEXT_REPAIR_TARGET_REQUIRED',
                'validation',
                [],
                'Context repair requires --feature=<feature>.',
            );
        }

        $payload = (new ContextRepairService($context->paths()))->repairFeature($featureName);
        $status = (string) ($payload['status'] ?? 'failed');

        return [
            'status' => in_array($status, ['repaired', 'no_changes'], true) ? 0 : 1,
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
     * @param array{
     *     status:string,
     *     feature:string,
     *     files_changed:list<string>,
     *     issues_repaired:list<string>,
     *     issues_remaining:list<string>,
     *     can_proceed:bool,
     *     requires_manual_action:bool,
     *     doctor_status:string,
     *     alignment_status:string,
     *     required_actions:list<string>,
     *     error?:array{code:string,message:string}
     * } $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            'Context repair: ' . $payload['feature'],
            'Status: ' . $payload['status'],
            'Can proceed: ' . ($payload['can_proceed'] ? 'yes' : 'no'),
            'Requires manual action: ' . ($payload['requires_manual_action'] ? 'yes' : 'no'),
            'Doctor: ' . $payload['doctor_status'],
            'Alignment: ' . $payload['alignment_status'],
            'Files changed:',
            ...$this->listLines($payload['files_changed']),
            'Issues repaired:',
            ...$this->listLines($payload['issues_repaired']),
            'Issues remaining:',
            ...$this->listLines($payload['issues_remaining']),
            'Required actions:',
            ...$this->listLines($payload['required_actions']),
        ];

        if (isset($payload['error'])) {
            $lines[] = 'Error: ' . $payload['error']['code'] . ': ' . $payload['error']['message'];
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function listLines(array $values): array
    {
        if ($values === []) {
            return ['- none'];
        }

        return array_values(array_map(
            static fn(string $value): string => '- ' . $value,
            $values,
        ));
    }
}
