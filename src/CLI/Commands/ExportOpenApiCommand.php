<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Support\FoundryError;

final class ExportOpenApiCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['export openapi'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'export' && ($args[1] ?? null) === 'openapi';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $format = strtolower((string) ($this->extractOption($args, '--format') ?? 'json'));
        if (!in_array($format, ['json', 'yaml', 'yml'], true)) {
            throw new FoundryError('CLI_OPENAPI_FORMAT_INVALID', 'validation', ['format' => $format], 'OpenAPI format must be json or yaml.');
        }

        $compile = $context->graphCompiler()->compile(new CompileOptions());
        $document = $context->openApiExporter()->build($compile->graph);
        $rendered = $context->openApiExporter()->render($document, $format);

        $extension = $format === 'json' ? 'json' : 'yaml';
        $outputDir = $context->paths()->join('app/.foundry/build/exports');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        $outputPath = $outputDir . '/openapi.' . $extension;
        file_put_contents($outputPath, $rendered . "\n");

        return [
            'status' => 0,
            'message' => $rendered,
            'payload' => [
                'format' => $extension,
                'file' => $outputPath,
                'openapi' => $document,
                'graph_version' => $compile->graph->graphVersion(),
                'framework_version' => $compile->graph->frameworkVersion(),
                'compiled_at' => $compile->graph->compiledAt(),
                'source_hash' => $compile->graph->sourceHash(),
            ],
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
}
