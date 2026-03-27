<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Compiler\CompileOptions;
use Foundry\Support\FoundryError;

final class GeneratePlatformCommand extends Command
{
    #[\Override]
    public function supportedSignatures(): array
    {
        return [
            'generate billing',
            'generate workflow',
            'generate orchestration',
            'generate search-index',
            'generate stream',
            'generate locale',
            'generate roles',
            'generate policy',
            'generate inspect-ui',
        ];
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
            'billing' => $this->generateBilling($args, $context, $force),
            'workflow' => $this->generateWorkflow($args, $context, $force),
            'orchestration' => $this->generateOrchestration($args, $context, $force),
            'search-index' => $this->generateSearchIndex($args, $context, $force),
            'stream' => $this->generateStream($args, $context, $force),
            'locale' => $this->generateLocale($args, $context, $force),
            'roles' => $this->generateRoles($context, $force),
            'policy' => $this->generatePolicy($args, $context, $force),
            'inspect-ui' => $this->generateInspectUi($context),
            default => throw new FoundryError('CLI_GENERATE_TARGET_INVALID', 'validation', ['target' => $target], 'Unsupported generation target.'),
        };
    }

    /**
     * @param array<int,string> $args
     */
    private function generateBilling(array $args, CommandContext $context, bool $force): array
    {
        $provider = (string) ($args[2] ?? '');
        if ($provider === '') {
            throw new FoundryError('CLI_BILLING_PROVIDER_REQUIRED', 'validation', [], 'Billing provider required.');
        }

        $result = $context->billingGenerator()->generate($provider, $force);

        return [
            'status' => 0,
            'message' => 'Billing generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateWorkflow(array $args, CommandContext $context, bool $force): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_WORKFLOW_REQUIRED', 'validation', [], 'Workflow name required.');
        }

        $definition = $this->extractOption($args, '--definition');
        if ($definition === null || $definition === '') {
            throw new FoundryError('CLI_WORKFLOW_DEFINITION_REQUIRED', 'validation', [], 'Workflow definition path required (--definition=<file>).');
        }

        $result = $context->workflowGenerator()->generate($name, $definition, $force);

        return [
            'status' => 0,
            'message' => 'Workflow generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateOrchestration(array $args, CommandContext $context, bool $force): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_ORCHESTRATION_REQUIRED', 'validation', [], 'Orchestration name required.');
        }

        $definition = $this->extractOption($args, '--definition');
        if ($definition === null || $definition === '') {
            throw new FoundryError('CLI_ORCHESTRATION_DEFINITION_REQUIRED', 'validation', [], 'Orchestration definition path required (--definition=<file>).');
        }

        $result = $context->orchestrationGenerator()->generate($name, $definition, $force);

        return [
            'status' => 0,
            'message' => 'Orchestration generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateSearchIndex(array $args, CommandContext $context, bool $force): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_SEARCH_INDEX_REQUIRED', 'validation', [], 'Search index name required.');
        }

        $definition = $this->extractOption($args, '--definition');
        if ($definition === null || $definition === '') {
            throw new FoundryError('CLI_SEARCH_DEFINITION_REQUIRED', 'validation', [], 'Search definition path required (--definition=<file>).');
        }

        $result = $context->searchIndexGenerator()->generate($name, $definition, $force);

        return [
            'status' => 0,
            'message' => 'Search index generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateStream(array $args, CommandContext $context, bool $force): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_STREAM_REQUIRED', 'validation', [], 'Stream name required.');
        }

        $result = $context->streamGenerator()->generate($name, $force);

        return [
            'status' => 0,
            'message' => 'Stream generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generateLocale(array $args, CommandContext $context, bool $force): array
    {
        $locale = (string) ($args[2] ?? '');
        if ($locale === '') {
            throw new FoundryError('CLI_LOCALE_REQUIRED', 'validation', [], 'Locale code required.');
        }

        $result = $context->localeGenerator()->generate($locale, $force);

        return [
            'status' => 0,
            'message' => 'Locale generated.',
            'payload' => $result,
        ];
    }

    private function generateRoles(CommandContext $context, bool $force): array
    {
        $result = $context->rolesGenerator()->generate($force);

        return [
            'status' => 0,
            'message' => 'Roles scaffolding generated.',
            'payload' => $result,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function generatePolicy(array $args, CommandContext $context, bool $force): array
    {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            throw new FoundryError('CLI_POLICY_REQUIRED', 'validation', [], 'Policy name required.');
        }

        $result = $context->policyGenerator()->generate($name, $force);

        return [
            'status' => 0,
            'message' => 'Policy generated.',
            'payload' => $result,
        ];
    }

    private function generateInspectUi(CommandContext $context): array
    {
        $compile = $context->graphCompiler()->compile(new CompileOptions());
        $result = $context->inspectUiGenerator()->generate($compile->graph);

        return [
            'status' => 0,
            'message' => 'Inspect UI generated.',
            'payload' => [
                'graph_version' => $compile->graph->graphVersion(),
                'source_hash' => $compile->graph->sourceHash(),
                'ui' => $result,
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
