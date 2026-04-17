<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextInspectionService;

final class VerifyContextCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['verify context'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'verify' && ($args[1] ?? null) === 'context';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $featureName = $this->extractOption($args, '--feature');
        $service = new ContextInspectionService($context->paths());
        $payload = ($featureName === null || $featureName === '')
            ? $service->verifyAll()
            : $service->verifyFeature($featureName);
        $status = (string) ($payload['status'] ?? 'fail');

        return [
            'status' => $status === 'pass' ? 0 : 1,
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
     * @param array<string,mixed> $payload
     */
    private function renderMessage(array $payload): string
    {
        return array_key_exists('feature', $payload)
            ? $this->renderFeature($payload)
            : $this->renderAll($payload);
    }

    /**
     * @param array{feature:string,status:string,can_proceed:bool,requires_repair:bool,consumable:bool,doctor_status:string,alignment_status:string,issues:list<array<string,mixed>>,required_actions:list<string>} $payload
     */
    private function renderFeature(array $payload): string
    {
        $lines = [
            'Context verification: ' . $payload['feature'],
            'Status: ' . $payload['status'],
            'Can proceed: ' . ($payload['can_proceed'] ? 'yes' : 'no'),
            'Requires repair: ' . ($payload['requires_repair'] ? 'yes' : 'no'),
            'Consumable: ' . ($payload['consumable'] ? 'yes' : 'no'),
            'Doctor: ' . $payload['doctor_status'],
            'Alignment: ' . $payload['alignment_status'],
            'Issues:',
        ];

        if ($payload['issues'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($payload['issues'] as $issue) {
                $lines[] = '- ' . (string) ($issue['source'] ?? '') . ' ' . (string) ($issue['code'] ?? '') . ': ' . (string) ($issue['message'] ?? '');
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
     * @param array{status:string,can_proceed:bool,requires_repair:bool,summary:array{pass:int,fail:int,total:int},features:list<array<string,mixed>>,required_actions:list<string>} $payload
     */
    private function renderAll(array $payload): string
    {
        $lines = [
            'Context verification: all',
            'Status: ' . $payload['status'],
            'Can proceed: ' . ($payload['can_proceed'] ? 'yes' : 'no'),
            'Requires repair: ' . ($payload['requires_repair'] ? 'yes' : 'no'),
            'Features:',
        ];

        if ($payload['features'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($payload['features'] as $feature) {
                if (!is_array($feature)) {
                    continue;
                }

                $lines[] = '- ' . (string) ($feature['feature'] ?? '') . ': ' . (string) ($feature['status'] ?? '') . ' (consumable: ' . (((bool) ($feature['consumable'] ?? false)) ? 'yes' : 'no') . ')';
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
