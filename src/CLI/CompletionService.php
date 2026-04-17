<?php

declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\Context\ExecutionSpecCatalog;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\FeatureNaming;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class CompletionService
{
    /**
     * @var array<string,mixed>|null
     */
    private ?array $staticTree = null;

    public function __construct(
        private readonly Paths $paths,
        private readonly ApiSurfaceRegistry $registry = new ApiSurfaceRegistry(),
    ) {}

    public function script(string $shell): string
    {
        return match ($this->normalizeShell($shell)) {
            'bash' => <<<'BASH'
# Foundry CLI completion for bash.
_foundry_completion_bash() {
    local index="${COMP_CWORD}"
    local current="${COMP_WORDS[COMP_CWORD]}"
    local IFS=$'\n'
    local candidates

    candidates="$(foundry completion bash --complete --index="${index}" --current="${current}" -- "${COMP_WORDS[@]}" 2>/dev/null)" || return 0
    COMPREPLY=($(compgen -W "${candidates}" -- "${current}"))
}

complete -F _foundry_completion_bash foundry
BASH,
            'zsh' => <<<'ZSH'
#compdef foundry
# Foundry CLI completion for zsh.
_foundry_completion_zsh() {
    local index=$((CURRENT - 1))
    local current="${words[CURRENT]}"
    local -a candidates

    candidates=("${(@f)$(foundry completion zsh --complete --index="${index}" --current="${current}" -- "${words[@]}" 2>/dev/null)}")
    _describe 'values' candidates
}

compdef _foundry_completion_zsh foundry
ZSH,
        };
    }

    /**
     * @param list<string> $words
     * @return list<string>
     */
    public function complete(string $shell, array $words, int $index, string $current = ''): array
    {
        $this->normalizeShell($shell);

        if ($index < 0) {
            throw new FoundryError(
                'CLI_COMPLETION_CONTEXT_INVALID',
                'validation',
                ['index' => $index],
                'Completion context is invalid.',
            );
        }

        $tokens = $this->commandTokens($words);
        $argIndex = min(max($index - 1, 0), count($tokens));
        if ($current === '' && array_key_exists($argIndex, $tokens)) {
            $current = (string) $tokens[$argIndex];
        }

        $candidates = $this->completionCandidates($tokens, $argIndex);

        return $this->filterCandidates($candidates, $current);
    }

    /**
     * @return list<string>
     */
    private function shells(): array
    {
        return ['bash', 'zsh'];
    }

    private function normalizeShell(string $shell): string
    {
        $shell = trim(strtolower($shell));
        if (in_array($shell, $this->shells(), true)) {
            return $shell;
        }

        throw new FoundryError(
            'CLI_COMPLETION_SHELL_UNSUPPORTED',
            'validation',
            ['shell' => $shell, 'supported_shells' => $this->shells()],
            'Unsupported completion shell. Use bash or zsh.',
        );
    }

    /**
     * @param list<string> $words
     * @return list<string>
     */
    private function commandTokens(array $words): array
    {
        if ($words === []) {
            return [];
        }

        return array_values(array_slice(array_map('strval', $words), 1));
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function completionCandidates(array $tokens, int $argIndex): array
    {
        $previousTokens = array_slice($tokens, 0, $argIndex);

        if ($argIndex === 0) {
            return $this->topLevelCommands();
        }

        if (($previousTokens[0] ?? '') === 'help') {
            return $this->staticChildren(array_slice($previousTokens, 1));
        }

        if ($previousTokens === ['completion']) {
            return $this->shells();
        }

        if ($previousTokens === ['implement', 'spec']) {
            return $this->featureNames();
        }

        if (($previousTokens[0] ?? '') === 'implement' && ($previousTokens[1] ?? '') === 'spec' && $argIndex === 3) {
            return $this->activeSpecIds((string) ($tokens[2] ?? ''));
        }

        return $this->staticChildren($previousTokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function staticTree(): array
    {
        if (is_array($this->staticTree)) {
            return $this->staticTree;
        }

        $tree = ['children' => []];

        foreach ($this->registry->cliCommands() as $entry) {
            $signature = trim((string) ($entry['signature'] ?? ''));
            if ($signature === '') {
                continue;
            }

            $tokens = array_values(array_filter(
                explode(' ', $signature),
                static fn(string $token): bool => $token !== '',
            ));

            $node = &$tree;
            foreach ($tokens as $token) {
                if ($this->placeholderToken($token)) {
                    break;
                }

                $node['children'][$token] ??= ['children' => []];
                $node = &$node['children'][$token];
            }
            unset($node);
        }

        return $this->staticTree = $tree;
    }

    private function placeholderToken(string $token): bool
    {
        return str_contains($token, '<') || str_contains($token, '>');
    }

    /**
     * @return list<string>
     */
    private function topLevelCommands(): array
    {
        /** @var array<string,mixed> $children */
        $children = $this->staticTree()['children'] ?? [];

        return $this->sortedKeys($children);
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    private function staticChildren(array $path): array
    {
        $node = $this->staticTree();

        foreach ($path as $token) {
            $child = $node['children'][$token] ?? null;
            if (!is_array($child)) {
                return [];
            }

            $node = $child;
        }

        /** @var array<string,mixed> $children */
        $children = $node['children'] ?? [];

        return $this->sortedKeys($children);
    }

    /**
     * @return list<string>
     */
    private function featureNames(): array
    {
        $directory = $this->paths->join('docs/specs');
        if (!is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return [];
        }

        $features = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!is_dir($directory . '/' . $entry)) {
                continue;
            }

            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $entry) !== 1) {
                continue;
            }

            $features[] = $entry;
        }

        sort($features);

        return array_values(array_unique($features));
    }

    /**
     * @return list<string>
     */
    private function activeSpecIds(string $feature): array
    {
        $feature = FeatureNaming::canonical(trim($feature));
        if ($feature === '') {
            return [];
        }

        try {
            $entries = (new ExecutionSpecCatalog($this->paths))->entries($feature);
        } catch (FoundryError) {
            return [];
        }

        $ids = [];
        foreach ($entries as $entry) {
            if (($entry['status'] ?? null) !== 'active') {
                continue;
            }

            $ids[] = (string) ($entry['id'] ?? '');
        }

        $ids = array_values(array_filter($ids, static fn(string $id): bool => $id !== ''));
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $candidates
     * @return list<string>
     */
    private function filterCandidates(array $candidates, string $prefix): array
    {
        $filtered = array_values(array_filter(
            array_values(array_unique($candidates)),
            static fn(string $candidate): bool => $prefix === '' || str_starts_with($candidate, $prefix),
        ));

        sort($filtered);

        return $filtered;
    }

    /**
     * @param array<string,mixed> $children
     * @return list<string>
     */
    private function sortedKeys(array $children): array
    {
        $keys = array_keys($children);
        sort($keys);

        return array_values(array_map('strval', $keys));
    }
}
