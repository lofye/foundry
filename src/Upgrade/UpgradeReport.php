<?php

declare(strict_types=1);

namespace Foundry\Upgrade;

final readonly class UpgradeReport
{
    /**
     * @param array<string,int> $summary
     * @param array<int,UpgradeIssue> $issues
     * @param array<string,mixed> $checks
     */
    public function __construct(
        public bool $ok,
        public string $currentVersion,
        public string $targetVersion,
        public int $graphVersion,
        public string $commandPrefix,
        public array $summary,
        public array $issues,
        public array $checks,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'current_version' => $this->currentVersion,
            'target_version' => $this->targetVersion,
            'graph_version' => $this->graphVersion,
            'command_prefix' => $this->commandPrefix,
            'summary' => $this->summary,
            'issues' => array_values(array_map(
                static fn(UpgradeIssue $issue): array => $issue->toArray(),
                $this->issues,
            )),
            'checks' => $this->checks,
        ];
    }

    public function renderHuman(): string
    {
        $headline = 'Upgrade check passed.';
        if (($this->summary['error'] ?? 0) > 0) {
            $headline = 'Upgrade check found blocking issues.';
        } elseif (($this->summary['warning'] ?? 0) > 0) {
            $headline = 'Upgrade check found issues to review.';
        }

        $lines = [
            $headline,
            sprintf('Current framework: %s', $this->currentVersion),
            sprintf('Target framework: %s', $this->targetVersion),
            sprintf(
                'Summary: %d error(s), %d warning(s), %d info.',
                (int) ($this->summary['error'] ?? 0),
                (int) ($this->summary['warning'] ?? 0),
                (int) ($this->summary['info'] ?? 0),
            ),
        ];

        foreach ($this->issues as $issue) {
            $lines[] = '';
            $lines[] = sprintf('[%s] %s', strtoupper($issue->severity), $issue->summary);

            $affected = [];
            foreach ($issue->affected as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_array($value)) {
                    $value = implode(', ', array_values(array_map('strval', $value)));
                }
                $affected[] = $key . '=' . (string) $value;
            }

            if ($affected !== []) {
                $lines[] = 'Affected: ' . implode('; ', $affected);
            }

            $lines[] = 'Why: ' . $issue->whyItMatters;
            $lines[] = 'Introduced in: ' . $issue->introducedIn;
            $lines[] = 'Migrate: ' . $issue->migration;

            if ($issue->reference !== null && $issue->reference !== '') {
                $lines[] = 'Reference: ' . $issue->reference;
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
