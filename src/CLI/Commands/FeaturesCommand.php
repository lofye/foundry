<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Monetization\MonetizationService;

final class FeaturesCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['features'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'features';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $status = (new MonetizationService())->status();
        $rows = array_values(array_map(
            static fn(array $row): array => [
                'feature' => (string) ($row['feature'] ?? ''),
                'type' => (string) ($row['type'] ?? 'capability'),
                'monetization' => (string) ($row['monetization'] ?? 'none'),
                'status' => (string) ($row['status'] ?? 'available'),
            ],
            (array) ($status['feature_statuses'] ?? []),
        ));

        $payload = [
            'license' => [
                'active' => (bool) ($status['valid'] ?? false),
                'tier' => (string) ($status['tier'] ?? 'free'),
            ],
            'features' => $rows,
        ];

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderTable($rows),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<int,array{feature:string,type:string,monetization:string,status:string}> $rows
     */
    private function renderTable(array $rows): string
    {
        $featureWidth = strlen('Feature');
        $typeWidth = strlen('Type');
        $monetizationWidth = strlen('Monetization');

        foreach ($rows as $row) {
            $featureWidth = max($featureWidth, strlen($row['feature']));
            $typeWidth = max($typeWidth, strlen($row['type']));
            $monetizationWidth = max($monetizationWidth, strlen($row['monetization']));
        }

        $lines = [
            str_pad('Feature', $featureWidth)
                . '  ' . str_pad('Type', $typeWidth)
                . '  ' . str_pad('Monetization', $monetizationWidth)
                . '  Status',
            str_repeat('-', $featureWidth)
                . '  ' . str_repeat('-', $typeWidth)
                . '  ' . str_repeat('-', $monetizationWidth)
                . '  ' . str_repeat('-', strlen('Status')),
        ];

        foreach ($rows as $row) {
            $lines[] = str_pad($row['feature'], $featureWidth)
                . '  ' . str_pad($row['type'], $typeWidth)
                . '  ' . str_pad($row['monetization'], $monetizationWidth)
                . '  ' . $row['status'];
        }

        return implode(PHP_EOL, $lines);
    }
}
