<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ContextDoctorService;
use Foundry\Support\FoundryError;

final class ContextDoctorCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['context doctor'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'context' && ($args[1] ?? null) === 'doctor';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $featureName = $this->extractOption($args, '--feature');
        $all = in_array('--all', $args, true);

        if ($featureName !== null && $all) {
            throw new FoundryError(
                'CLI_CONTEXT_DOCTOR_TARGET_CONFLICT',
                'validation',
                ['feature' => $featureName, 'all' => true],
                'Use either --feature=<feature> or --all, not both.',
            );
        }

        if (($featureName === null || $featureName === '') && !$all) {
            throw new FoundryError(
                'CLI_CONTEXT_DOCTOR_TARGET_REQUIRED',
                'validation',
                [],
                'Context doctor requires --feature=<feature> or --all.',
            );
        }

        $service = new ContextDoctorService($context->paths());
        $payload = $all ? $service->checkAll() : $service->checkFeature((string) $featureName);
        $status = (string) ($payload['status'] ?? 'non_compliant');

        return [
            'status' => in_array($status, ['ok', 'warning'], true) ? 0 : 1,
            'message' => $context->expectsJson() ? null : $this->renderMessage($payload, $all),
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
    private function renderMessage(array $payload, bool $all): string
    {
        return $all ? $this->renderAll($payload) : $this->renderFeature($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderFeature(array $payload): string
    {
        $files = (array) ($payload['files'] ?? []);
        $lines = [
            'Context doctor: ' . (string) ($payload['feature'] ?? ''),
            'Status: ' . (string) ($payload['status'] ?? ''),
            'Can proceed: ' . (((bool) ($payload['can_proceed'] ?? false)) ? 'yes' : 'no'),
            'Requires repair: ' . (((bool) ($payload['requires_repair'] ?? true)) ? 'yes' : 'no'),
            'Files:',
        ];

        foreach (['spec', 'state', 'decisions'] as $kind) {
            $file = (array) ($files[$kind] ?? []);
            $state = ((bool) ($file['exists'] ?? false) && (bool) ($file['valid'] ?? false)) ? 'ok' : 'needs repair';
            $lines[] = '- ' . $kind . ': ' . $state . ' ' . (string) ($file['path'] ?? '');
        }

        $lines[] = 'Required actions:';
        foreach ($this->actionLines((array) ($payload['required_actions'] ?? [])) as $line) {
            $lines[] = $line;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderAll(array $payload): string
    {
        $lines = [
            'Context doctor: all',
            'Status: ' . (string) ($payload['status'] ?? ''),
            'Can proceed: ' . (((bool) ($payload['can_proceed'] ?? false)) ? 'yes' : 'no'),
            'Requires repair: ' . (((bool) ($payload['requires_repair'] ?? true)) ? 'yes' : 'no'),
            'Features:',
        ];

        $features = (array) ($payload['features'] ?? []);
        if ($features === []) {
            $lines[] = '- none';
        }

        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $lines[] = '- ' . (string) ($feature['feature'] ?? '') . ': ' . (string) ($feature['status'] ?? '');
        }

        $lines[] = 'Required actions:';
        foreach ($this->actionLines((array) ($payload['required_actions'] ?? [])) as $line) {
            $lines[] = $line;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<int,mixed> $actions
     * @return list<string>
     */
    private function actionLines(array $actions): array
    {
        if ($actions === []) {
            return ['- none'];
        }

        return array_values(array_map(
            static fn(mixed $action): string => '- ' . (string) $action,
            $actions,
        ));
    }
}
