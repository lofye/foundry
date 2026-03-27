<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Support\FoundryError;

final class GenerateIntegrationCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return ['generate notification', 'generate api-resource', 'generate docs'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        if (($args[0] ?? null) !== 'generate') {
            return false;
        }

        return $this->supportsSignature('generate ' . (string) ($args[1] ?? ''));
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $target = (string) ($args[1] ?? '');
        $force = in_array('--force', $args, true);

        return match ($target) {
            'notification' => $this->generateNotification($args, $context, $force),
            'api-resource' => $this->generateApiResource($args, $context, $force),
            'docs' => $this->generateDocs($args, $context),
            default => throw new FoundryError('CLI_GENERATE_TARGET_INVALID', 'validation', ['target' => $target], 'Unsupported generation target.'),
        };
    }

    /**
     * @param array<int,string> $args
     */
    private function generateNotification(array $args, CommandContext $context, bool $force): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_NOTIFICATION_REQUIRED', 'validation', [], 'Notification name required.');
        }

        $result = $context->notificationGenerator()->generate($name, $force);

        return [
            'status' => 0,
            'message' => 'Notification generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateApiResource(array $args, CommandContext $context, bool $force): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_API_RESOURCE_REQUIRED', 'validation', [], 'API resource name required.');
        }

        $definition = $this->extractOption($args, '--definition');
        if ($definition === null || $definition === '') {
            throw new FoundryError('CLI_API_RESOURCE_DEFINITION_REQUIRED', 'validation', [], 'API resource definition path required (--definition=<file>).');
        }

        $result = $context->apiResourceGenerator()->generate($name, $definition, $force);

        return [
            'status' => 0,
            'message' => 'API resource generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateDocs(array $args, CommandContext $context): array
    {
        $format = strtolower((string) ($this->extractOption($args, '--format') ?? 'markdown'));
        if (!in_array($format, ['markdown', 'html'], true)) {
            throw new FoundryError('CLI_DOCS_FORMAT_INVALID', 'validation', ['format' => $format], 'Docs format must be markdown or html.');
        }

        $compile = $context->graphCompiler()->compile(new CompileOptions());
        $result = $context->docsGenerator()->generate($compile->graph, $format);

        return [
            'status' => 0,
            'message' => 'Documentation generated.',
            'payload' => [
                'format' => $format,
                'graph_version' => $compile->graph->graphVersion(),
                'framework_version' => $compile->graph->frameworkVersion(),
                'compiled_at' => $compile->graph->compiledAt(),
                'source_hash' => $compile->graph->sourceHash(),
                'docs' => $result,
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
