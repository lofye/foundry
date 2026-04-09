<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextInspectionService;
use Foundry\Support\FoundryError;

final class InspectContextCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['inspect context'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'inspect' && ($args[1] ?? null) === 'context';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $featureName = (string) ($args[2] ?? '');
        if ($featureName === '') {
            throw new FoundryError('CLI_FEATURE_REQUIRED', 'validation', [], 'Feature name required.');
        }

        $payload = (new ContextInspectionService($context->paths()))->inspectFeature($featureName);

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array{
     *     feature:string,
     *     doctor:array<string,mixed>,
     *     alignment:array<string,mixed>,
     *     summary:array{doctor_status:string,alignment_status:string}
     * } $payload
     */
    private function renderMessage(array $payload): string
    {
        $lines = [
            'Context inspection: ' . $payload['feature'],
            'Doctor: ' . $payload['summary']['doctor_status'],
            'Alignment: ' . $payload['summary']['alignment_status'],
            'Required actions:',
        ];

        $actions = array_values(array_unique(array_merge(
            array_values(array_map('strval', (array) ($payload['doctor']['required_actions'] ?? []))),
            array_values(array_map('strval', (array) ($payload['alignment']['required_actions'] ?? []))),
        )));

        if ($actions === []) {
            $lines[] = '- none';
        } else {
            foreach ($actions as $action) {
                $lines[] = '- ' . $action;
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
