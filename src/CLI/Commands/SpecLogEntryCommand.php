<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\Context\ExecutionSpec;
use Foundry\Context\ExecutionSpecFilename;
use Foundry\Context\ExecutionSpecImplementationLogService;
use Foundry\Context\ExecutionSpecResolver;
use Foundry\Context\FeatureNameValidator;
use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;

final class SpecLogEntryCommand extends Command
{
    /**
     * @var \Closure():\DateTimeImmutable|null
     */
    private readonly ?\Closure $nowProvider;

    public function __construct(?\Closure $nowProvider = null)
    {
        $this->nowProvider = $nowProvider;
    }

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['spec:log-entry'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'spec:log-entry';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        try {
            $executionSpec = $this->resolveExecutionSpec($args, $context);
            $payload = (new ExecutionSpecImplementationLogService($context->paths(), $this->nowProvider))
                ->suggestedEntry($executionSpec);

            if ($payload === null) {
                throw $this->draftOnlyError([
                    'feature' => $executionSpec->feature,
                    'id' => $executionSpec->id,
                    'matches' => [$executionSpec->path],
                    'path' => $executionSpec->path,
                ]);
            }
        } catch (FoundryError $error) {
            if ($error->errorCode === 'EXECUTION_SPEC_DRAFT_ONLY') {
                throw $this->draftOnlyError($error->details);
            }

            throw $error;
        }

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : rtrim((string) $payload['entry'], "\n"),
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<int,string> $args
     */
    private function resolveExecutionSpec(array $args, CommandContext $context): ExecutionSpec
    {
        $positionals = $this->positionalArguments($args);
        $resolver = new ExecutionSpecResolver($context->paths());

        return match (count($positionals)) {
            0 => throw new FoundryError(
                'CLI_SPEC_LOG_ENTRY_TARGET_REQUIRED',
                'validation',
                [],
                'Spec log-entry target required.',
            ),
            1 => $this->resolveSinglePositionalSpec($resolver, $positionals[0]),
            2 => $this->resolveWithinFeature($resolver, $positionals[0], $positionals[1], $context),
            default => throw new FoundryError(
                'CLI_SPEC_LOG_ENTRY_ARGUMENTS_INVALID',
                'validation',
                ['arguments' => $positionals],
                'Spec log-entry accepts either <feature>/<id>-<slug>, <id>-<slug>, or <feature> <id>.',
            ),
        };
    }

    private function resolveSinglePositionalSpec(ExecutionSpecResolver $resolver, string $argument): ExecutionSpec
    {
        $trimmed = trim($argument);
        $normalized = $this->stripMarkdownExtension($trimmed);

        $draftPath = $this->draftPathFromArgument($trimmed);
        if ($draftPath !== null) {
            throw $this->draftOnlyError([
                'feature' => $draftPath['feature'],
                'id' => $draftPath['id'],
                'matches' => ['docs/features/' . $draftPath['feature'] . '/specs/drafts/' . $draftPath['name'] . '.md'],
                'path' => 'docs/features/' . $draftPath['feature'] . '/specs/drafts/' . $draftPath['name'] . '.md',
            ]);
        }

        if (
            !str_contains($trimmed, '/')
            && !str_starts_with($trimmed, 'docs/')
            && !str_starts_with($trimmed, 'Features/')
            && !ExecutionSpecFilename::isCanonicalName($normalized)
            && (new FeatureNameValidator())->validate(FeatureNaming::canonical($normalized))->valid
        ) {
            throw new FoundryError(
                'CLI_SPEC_LOG_ENTRY_ID_REQUIRED',
                'validation',
                ['feature' => FeatureNaming::canonical($normalized)],
                'Spec log-entry id required when invoking `spec:log-entry <feature> <id>`.',
            );
        }

        return $resolver->resolve($trimmed);
    }

    private function resolveWithinFeature(ExecutionSpecResolver $resolver, string $feature, string $id, CommandContext $context): ExecutionSpec
    {
        $canonicalFeature = FeatureNaming::canonical(trim($feature));
        if ($this->featureExists($canonicalFeature, $context)) {
            $catalog = new \Foundry\Context\ExecutionSpecCatalog($context->paths());
            $catalog->assertContiguous($canonicalFeature, $catalog->entries($canonicalFeature));
        }

        return $resolver->resolveWithinFeature($feature, $id);
    }

    /**
     * @param array<int,string> $args
     * @return list<string>
     */
    private function positionalArguments(array $args): array
    {
        return array_values(array_slice($args, 1));
    }

    private function stripMarkdownExtension(string $value): string
    {
        return str_ends_with($value, '.md')
            ? substr($value, 0, -strlen('.md'))
            : $value;
    }

    /**
     * @return array{
     *     feature:string,
     *     name:string,
     *     id:string,
     *     slug:string,
     *     segments:list<int>,
     *     parent_id:?string
     * }|null
     */
    private function draftPathFromArgument(string $argument): ?array
    {
        $normalized = str_replace('\\', '/', trim($argument));

        if (!str_starts_with($normalized, 'docs/')) {
            if (!str_starts_with($normalized, 'Features/')) {
                return null;
            }
        }

        $path = str_ends_with($normalized, '.md') ? $normalized : $normalized . '.md';

        return ExecutionSpecFilename::parseDraftPath($path);
    }

    /**
     * @param array<string,mixed> $details
     */
    private function draftOnlyError(array $details): FoundryError
    {
        return new FoundryError(
            'EXECUTION_SPEC_DRAFT_ONLY',
            'validation',
            $details,
            'Draft execution specs do not require implementation-log coverage. Promote the spec only if it later becomes active and implemented.',
        );
    }

    private function featureExists(string $feature, CommandContext $context): bool
    {
        $pascal = $this->pascalFromSlug($feature);
        $paths = [
            'Features/' . $pascal,
            'Features/' . $pascal . '/specs/drafts',
            'Features/' . $pascal . '/' . $feature . '.spec.md',
            'Features/' . $pascal . '/' . $feature . '.md',
            'Features/' . $pascal . '/' . $feature . '.decisions.md',
            'docs/features/' . $feature,
            'docs/features/' . $feature . '/specs/drafts',
            'docs/features/' . $feature . '/' . $feature . '.spec.md',
            'docs/features/' . $feature . '/' . $feature . '.md',
            'docs/features/' . $feature . '/' . $feature . '.decisions.md',
        ];

        foreach ($paths as $path) {
            if (is_dir($context->paths()->join($path)) || is_file($context->paths()->join($path))) {
                return true;
            }
        }

        return false;
    }

    private function pascalFromSlug(string $slug): string
    {
        $parts = array_filter(explode('-', $slug), static fn(string $part): bool => $part !== '');

        return implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
    }
}
