<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Tooling\BuildArtifactStore;

final class HistoryCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['history'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'history';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $kind = $this->extractKind($args);
        $entries = (new BuildArtifactStore($context->graphCompiler()->buildLayout()))->listHistory($kind);

        $summary = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryKind = (string) ($entry['kind'] ?? '');
            $summary[$entryKind] = ($summary[$entryKind] ?? 0) + 1;
        }
        ksort($summary);

        return [
            'status' => 0,
            'message' => 'History loaded.',
            'payload' => [
                'kind_filter' => $kind,
                'summary' => $summary,
                'entries' => $entries,
            ],
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function extractKind(array $args): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, '--kind=')) {
                $value = substr($arg, strlen('--kind='));

                return $value !== '' ? $value : null;
            }

            if ($arg === '--kind') {
                $value = (string) ($args[$index + 1] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
