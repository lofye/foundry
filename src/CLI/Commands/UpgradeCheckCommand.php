<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Support\FoundryError;

final class UpgradeCheckCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['upgrade-check'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'upgrade-check';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = $this->parseTarget($args);
        $analyzer = $context->upgradeAnalyzer();
        if ($target !== null && !$analyzer->isValidTargetVersion($target)) {
            throw new FoundryError(
                'CLI_UPGRADE_TARGET_INVALID',
                'validation',
                ['target' => $target],
                'Upgrade target must be a semantic version like 1.0.0 or dev-main.',
            );
        }

        $report = $analyzer->analyze($target);

        return [
            'status' => $report->ok ? 0 : 1,
            'message' => $context->expectsJson() ? null : $report->renderHuman(),
            'payload' => $context->expectsJson() ? $report->toArray() : null,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function parseTarget(array $args): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, '--target=')) {
                $value = substr($arg, strlen('--target='));

                return $value !== '' ? $value : null;
            }

            if ($arg === '--target') {
                $value = (string) ($args[$index + 1] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
