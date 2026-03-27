<?php
declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\Concerns\InteractsWithGraphInspection;
use Foundry\Support\FoundryError;

final class ExportGraphCommand extends Command
{
    use InteractsWithGraphInspection;

    /**
     * @var array<int,string>
     */
    private array $allowedFormats = ['json', 'dot', 'mermaid', 'svg'];

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'export' && ($args[1] ?? null) === 'graph';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $options = $this->parseGraphOptions($args);
        $format = strtolower((string) ($options['format'] ?? 'json'));
        $options['format'] = $format;

        if (!in_array($format, $this->allowedFormats, true)) {
            throw new FoundryError(
                'CLI_GRAPH_FORMAT_INVALID',
                'validation',
                ['format' => $format],
                'Unsupported graph format. Use json, dot, mermaid, or svg.',
            );
        }

        $feature = is_string($options['feature'] ?? null) ? $options['feature'] : null;
        if ($feature !== null && !$this->featureExists($context, $feature)) {
            throw new FoundryError(
                'FEATURE_NOT_FOUND',
                'not_found',
                ['feature' => $feature],
                'Feature not found.',
            );
        }

        $payload = $this->buildGraphInspectionPayload($context, $options);
        $file = $this->graphExportPath($context, $payload, $format, is_string($options['output'] ?? null) ? $options['output'] : null);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $rendered = (string) ($payload['rendered'] ?? '');
        file_put_contents($file, $rendered . ($rendered !== '' && !str_ends_with($rendered, "\n") ? "\n" : ''));

        $payload['file'] = $file;

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderGraphInspectionMessage($payload, false, $file),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    private function featureExists(CommandContext $context, string $feature): bool
    {
        return is_dir($context->paths()->features() . '/' . $feature);
    }
}
