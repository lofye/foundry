<?php
declare(strict_types=1);

namespace Foundry\Explain\Renderers;

use Foundry\Explain\ExplanationPlan;

final class MarkdownExplanationRenderer implements ExplanationRendererInterface
{
    public function render(ExplanationPlan $plan): string
    {
        $payload = $plan->toArray();
        $lines = [
            '## Subject',
            '- **Label:** ' . (string) ($payload['subject']['label'] ?? ''),
            '- **Kind:** ' . (string) ($payload['subject']['kind'] ?? ''),
        ];

        $summary = trim((string) ($payload['summary']['text'] ?? ''));
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = '## Summary';
            $lines[] = $summary;
        }

        $steps = array_values(array_map('strval', (array) ($payload['execution_flow']['steps'] ?? [])));
        if ($steps !== []) {
            $lines[] = '';
            $lines[] = '## Execution Flow';
            foreach ($steps as $step) {
                $lines[] = '- ' . $step;
            }
        }

        $commands = array_values(array_map('strval', (array) ($payload['related_commands'] ?? [])));
        if ($commands !== []) {
            $lines[] = '';
            $lines[] = '## Related Commands';
            foreach ($commands as $command) {
                $lines[] = '- `' . $command . '`';
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
