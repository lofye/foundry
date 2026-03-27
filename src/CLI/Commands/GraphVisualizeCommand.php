<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\Concerns\InteractsWithGraphInspection;
use Foundry\Support\FoundryError;

final class GraphVisualizeCommand extends Command
{
    use InteractsWithGraphInspection;

    /**
     * @var array<int,string>
     */
    private array $allowedFormats = ['mermaid', 'dot', 'json', 'svg'];

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['graph visualize'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'graph' && ($args[1] ?? null) === 'visualize';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $options = $this->parseGraphOptions($args);
        $format = strtolower((string) ($options['format'] ?? 'mermaid'));
        $options['format'] = $format;

        if (!in_array($format, $this->allowedFormats, true)) {
            throw new FoundryError(
                'CLI_GRAPH_FORMAT_INVALID',
                'validation',
                ['format' => $format],
                'Unsupported graph format. Use mermaid, dot, json, or svg.',
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

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $this->renderGraphInspectionMessage($payload, true),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    private function featureExists(CommandContext $context, string $feature): bool
    {
        return is_dir($context->paths()->features() . '/' . $feature);
    }
}
