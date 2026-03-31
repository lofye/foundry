<?php

declare(strict_types=1);

namespace Foundry\CLI\Commands;

use Foundry\CLI\Command;
use Foundry\CLI\CommandContext;
use Foundry\CLI\Commands\Concerns\InteractsWithLicensing;
use Foundry\Compiler\CompileOptions;
use Foundry\Explain\Diff\ExplainDiffService;
use Foundry\Explain\ExplainOptions;
use Foundry\Explain\ExplainSupport;
use Foundry\Explain\ExplainTarget;
use Foundry\Explain\Snapshot\ExplainSnapshotService;
use Foundry\Git\GitRepositoryInspector;
use Foundry\Monetization\FeatureFlags;
use Foundry\Pro\ArchitectureExplainer;
use Foundry\Support\FoundryError;

final class ExplainCommand extends Command
{
    use InteractsWithLicensing;

    #[\Override]
    public function supportedSignatures(): array
    {
        return ['explain'];
    }

    #[\Override]
    public function matches(array $args): bool
    {
        return ($args[0] ?? null) === 'explain';
    }

    #[\Override]
    public function run(array $args, CommandContext $context): array
    {
        $this->monetizationContext('explain', [FeatureFlags::PRO_EXPLAIN_PLUS]);
        [$target, $targetKind, $options, $diff, $includeGit] = $this->parseExplainArgs($args);

        if ($diff) {
            $this->assertDiffModeOptions($target, $targetKind, $options, $includeGit);
            $diffService = new ExplainDiffService(
                $context->paths(),
                new ExplainSnapshotService($context->paths(), $context->apiSurfaceRegistry()),
            );
            $payload = $diffService->loadLast();

            return [
                'status' => 0,
                'message' => $context->expectsJson() ? null : $diffService->render($payload),
                'payload' => $context->expectsJson() ? $payload : null,
            ];
        }

        $compiler = $context->graphCompiler();
        $graph = $compiler->loadGraph() ?? $compiler->compile(new CompileOptions())->graph;
        if ($target === '') {
            $target = ExplainSupport::defaultTarget($graph);
        }

        $response = (new ArchitectureExplainer(
            paths: $context->paths(),
            impactAnalyzer: $compiler->impactAnalyzer(),
            apiSurfaceRegistry: $context->apiSurfaceRegistry(),
            extensionRows: $context->extensionRegistry()->inspectRows(),
        ))->explain($graph, ExplainTarget::parse($target, $targetKind), $options);

        $payload = $response->toArray();
        $message = $response->rendered;
        if ($includeGit) {
            $git = $this->gitContext($payload, $context);
            $payload['git'] = $git;
            if (!$context->expectsJson()) {
                $message = rtrim($message) . PHP_EOL . PHP_EOL . $this->renderGitContext($git);
            }
        }

        return [
            'status' => 0,
            'message' => $context->expectsJson() ? null : $message,
            'payload' => $context->expectsJson() ? $payload : null,
        ];
    }

    /**
     * @param array<int,string> $args
     * @return array{0:string,1:?string,2:ExplainOptions,3:bool,4:bool}
     */
    private function parseExplainArgs(array $args): array
    {
        $targetParts = [];
        $targetKind = null;
        $format = 'text';
        $deep = false;
        $includeDiagnostics = true;
        $includeNeighbors = true;
        $includeExecutionFlow = true;
        $diff = false;
        $includeGit = false;

        for ($index = 1; $index < count($args); $index++) {
            $arg = (string) $args[$index];

            if ($arg === '--diff') {
                $diff = true;
                continue;
            }

            if ($arg === '--markdown') {
                $format = 'markdown';
                continue;
            }

            if ($arg === '--deep') {
                $deep = true;
                continue;
            }

            if ($arg === '--git') {
                $includeGit = true;
                continue;
            }

            if ($arg === '--no-diagnostics') {
                $includeDiagnostics = false;
                continue;
            }

            if ($arg === '--no-neighbors') {
                $includeNeighbors = false;
                continue;
            }

            if ($arg === '--neighbors') {
                $includeNeighbors = true;
                continue;
            }

            if ($arg === '--no-flow') {
                $includeExecutionFlow = false;
                continue;
            }

            if (str_starts_with($arg, '--type=')) {
                $targetKind = trim(substr($arg, strlen('--type=')));
                continue;
            }

            if ($arg === '--type') {
                $targetKind = trim((string) ($args[$index + 1] ?? ''));
                $index++;
                continue;
            }

            $targetParts[] = $arg;
        }

        return [
            trim(implode(' ', $targetParts)),
            $targetKind !== '' ? $targetKind : null,
            new ExplainOptions(
                format: $format,
                deep: $deep,
                includeDiagnostics: $includeDiagnostics,
                includeNeighbors: $includeNeighbors,
                includeExecutionFlow: $includeExecutionFlow,
                type: $targetKind !== '' ? $targetKind : null,
            ),
            $diff,
            $includeGit,
        ];
    }

    private function assertDiffModeOptions(string $target, ?string $targetKind, ExplainOptions $options, bool $includeGit): void
    {
        if (
            trim($target) !== ''
            || $targetKind !== null
            || $options->format !== 'text'
            || $options->deep
            || $options->includeDiagnostics !== true
            || $options->includeNeighbors !== true
            || $options->includeExecutionFlow !== true
            || $includeGit
        ) {
            throw new FoundryError(
                'EXPLAIN_DIFF_ARGUMENTS_INVALID',
                'validation',
                [],
                'Explain diff supports only `foundry explain --diff` and `foundry explain --diff --json`.',
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function gitContext(array $payload, CommandContext $context): array
    {
        $inspector = new GitRepositoryInspector($context->paths()->root());
        $state = $inspector->inspect();
        if (($state['available'] ?? false) !== true) {
            return ['available' => false];
        }

        $paths = $this->collectProjectPaths($payload);
        $relevantFiles = $inspector->describePaths($paths, $state);
        $dirtyRelevantFiles = count(array_filter(
            $relevantFiles,
            static fn(array $row): bool => (bool) ($row['dirty'] ?? false),
        ));

        return [
            'available' => true,
            'repository_root' => $this->relativePath($context, (string) ($state['repository_root'] ?? '')),
            'branch' => $state['branch'] ?? null,
            'head' => $state['head'] ?? null,
            'dirty' => (bool) ($state['dirty'] ?? false),
            'relevant_files' => $relevantFiles,
            'summary' => [
                'relevant_files' => count($relevantFiles),
                'dirty_relevant_files' => $dirtyRelevantFiles,
                'untracked_relevant_files' => count(array_filter(
                    $relevantFiles,
                    static fn(array $row): bool => (bool) ($row['untracked'] ?? false),
                )),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $git
     */
    private function renderGitContext(array $git): string
    {
        if (($git['available'] ?? false) !== true) {
            return "Git:\n- Repository not detected";
        }

        $lines = ['Git:'];
        $branch = trim((string) ($git['branch'] ?? ''));
        $head = trim((string) ($git['head'] ?? ''));
        $lines[] = sprintf(
            '- Branch: %s%s',
            $branch !== '' ? $branch : 'detached',
            $head !== '' ? ' @ ' . substr($head, 0, 12) : '',
        );
        $lines[] = '- Working tree: ' . (((bool) ($git['dirty'] ?? false)) ? 'dirty' : 'clean');
        $summary = is_array($git['summary'] ?? null) ? $git['summary'] : [];
        $lines[] = sprintf(
            '- Relevant files: %d (%d dirty)',
            (int) ($summary['relevant_files'] ?? 0),
            (int) ($summary['dirty_relevant_files'] ?? 0),
        );

        $relevantFiles = array_values(array_filter((array) ($git['relevant_files'] ?? []), 'is_array'));
        foreach (array_slice($relevantFiles, 0, 3) as $row) {
            if (($row['dirty'] ?? false) !== true) {
                continue;
            }

            $lines[] = '- Dirty relevant file: ' . (string) ($row['path'] ?? '');
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $value
     * @return array<int,string>
     */
    private function collectProjectPaths(array $value): array
    {
        $paths = [];
        $this->collectProjectPathsRecursive($value, $paths);
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * @param array<int,string> $paths
     */
    private function collectProjectPathsRecursive(mixed $value, array &$paths): void
    {
        if (is_string($value) && $this->looksLikeProjectPath($value)) {
            $paths[] = $value;
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectProjectPathsRecursive($item, $paths);
        }
    }

    private function looksLikeProjectPath(string $value): bool
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return false;
        }

        if (in_array($value, ['composer.json', 'foundry'], true)) {
            return true;
        }

        foreach (['app/', 'config/', 'database/', 'docs/', '.foundry/', 'bootstrap/', 'lang/', 'public/', 'storage/'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(CommandContext $context, string $absolutePath): string
    {
        $absolutePath = trim($absolutePath);
        if ($absolutePath === '') {
            return '.';
        }

        $root = rtrim($context->paths()->root(), '/');
        if ($absolutePath === $root) {
            return '.';
        }

        $prefix = $root . '/';

        return str_starts_with($absolutePath, $prefix)
            ? substr($absolutePath, strlen($prefix))
            : $absolutePath;
    }
}
