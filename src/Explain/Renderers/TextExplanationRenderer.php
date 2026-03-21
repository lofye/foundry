<?php
declare(strict_types=1);

namespace Foundry\Explain\Renderers;

use Foundry\Explain\ExplanationPlan;

final class TextExplanationRenderer implements ExplanationRendererInterface
{
    public function render(ExplanationPlan $plan): string
    {
        $payload = $plan->toArray();
        $lines = [];

        $lines[] = 'Subject';
        $lines[] = '  ' . (string) ($payload['subject']['label'] ?? '');
        $lines[] = '  kind: ' . (string) ($payload['subject']['kind'] ?? '');

        $summary = trim((string) (($payload['summary']['text'] ?? '')));
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = 'Summary';
            $lines[] = '  ' . $summary;
        }

        $dependsOn = (array) ($payload['relationships']['depends_on'] ?? []);
        if ($dependsOn !== []) {
            $lines[] = '';
            $lines[] = 'Depends On';
            foreach ($dependsOn as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $lines[] = '  ' . (string) ($row['label'] ?? $row['id'] ?? '');
            }
        }

        $usedBy = (array) ($payload['relationships']['depended_on_by'] ?? []);
        if ($usedBy !== []) {
            $lines[] = '';
            $lines[] = 'Used By';
            foreach ($usedBy as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $lines[] = '  ' . (string) ($row['label'] ?? $row['id'] ?? '');
            }
        }

        $steps = array_values(array_map('strval', (array) ($payload['execution_flow']['steps'] ?? [])));
        if ($steps !== []) {
            $lines[] = '';
            $lines[] = 'Execution Flow';
            foreach ($steps as $step) {
                $lines[] = '  ' . $step;
            }
        }

        $diagnostics = (array) ($payload['diagnostics']['items'] ?? []);
        $lines[] = '';
        $lines[] = 'Related Diagnostics';
        if ($diagnostics === []) {
            $lines[] = '  none';
        } else {
            foreach ($diagnostics as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $lines[] = '  ' . (string) ($row['message'] ?? $row['code'] ?? '');
            }
        }

        $commands = array_values(array_map('strval', (array) ($payload['related_commands'] ?? [])));
        if ($commands !== []) {
            $lines[] = '';
            $lines[] = 'Related Commands';
            foreach ($commands as $command) {
                $lines[] = '  ' . $command;
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
