<?php

declare(strict_types=1);

namespace Foundry\Git;

final class GitRepositoryInspector
{
    public function __construct(private readonly string $workingDirectory) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $root = $this->run(['rev-parse', '--show-toplevel'], allowFailure: true);
        if (!$root['ok']) {
            return [
                'available' => false,
                'repository_root' => null,
                'branch' => null,
                'head' => null,
                'dirty' => false,
                'changed_files' => [],
                'staged_files' => [],
                'unstaged_files' => [],
                'untracked_files' => [],
                'ignored_internal_files' => [],
                'safety_relevant' => [
                    'dirty' => false,
                    'changed_files' => [],
                    'staged_files' => [],
                    'unstaged_files' => [],
                    'untracked_files' => [],
                ],
                'status_by_file' => [],
            ];
        }

        $status = $this->run(['status', '--porcelain=v1', '--untracked-files=all'], allowFailure: true);
        $statusByFile = [];
        $changedFiles = [];
        $stagedFiles = [];
        $unstagedFiles = [];
        $untrackedFiles = [];

        foreach ($this->lines($status['stdout']) as $line) {
            $parsed = $this->parseStatusLine($line);
            if ($parsed === null) {
                continue;
            }

            $path = $parsed['path'];
            $changedFiles[] = $path;
            $statusByFile[$path] = $parsed;

            if ($parsed['staged']) {
                $stagedFiles[] = $path;
            }

            if ($parsed['unstaged']) {
                $unstagedFiles[] = $path;
            }

            if ($parsed['untracked']) {
                $untrackedFiles[] = $path;
            }
        }

        $branch = $this->trimOrNull($this->run(['symbolic-ref', '--quiet', '--short', 'HEAD'], allowFailure: true)['stdout'])
            ?? $this->trimOrNull($this->run(['rev-parse', '--abbrev-ref', 'HEAD'], allowFailure: true)['stdout']);
        $head = $this->trimOrNull($this->run(['rev-parse', 'HEAD'], allowFailure: true)['stdout']);

        $changedFiles = $this->normalizePaths($changedFiles);
        $stagedFiles = $this->normalizePaths($stagedFiles);
        $unstagedFiles = $this->normalizePaths($unstagedFiles);
        $untrackedFiles = $this->normalizePaths($untrackedFiles);
        ksort($statusByFile);

        $ignoredInternalFiles = array_values(array_filter(
            $changedFiles,
            fn(string $path): bool => $this->isInternalArtifactPath($path),
        ));
        sort($ignoredInternalFiles);

        return [
            'available' => true,
            'repository_root' => $this->trimOrNull($root['stdout']),
            'branch' => $branch === 'HEAD' ? null : $branch,
            'head' => $head,
            'dirty' => $changedFiles !== [],
            'changed_files' => $changedFiles,
            'staged_files' => $stagedFiles,
            'unstaged_files' => $unstagedFiles,
            'untracked_files' => $untrackedFiles,
            'ignored_internal_files' => $ignoredInternalFiles,
            'safety_relevant' => [
                'dirty' => $this->safetyRelevantPaths($changedFiles) !== [],
                'changed_files' => $this->safetyRelevantPaths($changedFiles),
                'staged_files' => $this->safetyRelevantPaths($stagedFiles),
                'unstaged_files' => $this->safetyRelevantPaths($unstagedFiles),
                'untracked_files' => $this->safetyRelevantPaths($untrackedFiles),
            ],
            'status_by_file' => $statusByFile,
        ];
    }

    /**
     * @param array<int,string> $paths
     * @param array<string,mixed>|null $state
     * @return array<int,array<string,mixed>>
     */
    public function describePaths(array $paths, ?array $state = null): array
    {
        $gitState = $state ?? $this->inspect();
        if (($gitState['available'] ?? false) !== true) {
            return [];
        }

        $rows = [];
        $statusByFile = is_array($gitState['status_by_file'] ?? null) ? $gitState['status_by_file'] : [];

        foreach ($this->normalizePaths($paths) as $path) {
            $status = is_array($statusByFile[$path] ?? null) ? $statusByFile[$path] : [];
            $absolutePath = $this->workingDirectory . '/' . $path;
            $lastCommit = null;

            if (($status['untracked'] ?? false) !== true) {
                $lastCommit = $this->trimOrNull($this->run(
                    ['log', '-n', '1', '--format=%H', '--', $path],
                    allowFailure: true,
                )['stdout']);
            }

            $rows[] = [
                'path' => $path,
                'exists' => file_exists($absolutePath),
                'changed' => (bool) ($status['changed'] ?? false),
                'dirty' => (bool) ($status['changed'] ?? false),
                'staged' => (bool) ($status['staged'] ?? false),
                'unstaged' => (bool) ($status['unstaged'] ?? false),
                'untracked' => (bool) ($status['untracked'] ?? false),
                'status' => $this->statusLabel($status),
                'last_commit' => $lastCommit,
            ];
        }

        usort($rows, static fn(array $left, array $right): int => strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? '')));

        return $rows;
    }

    /**
     * @param array<int,string> $paths
     * @return array<string,mixed>
     */
    public function commit(array $paths, string $message): array
    {
        $state = $this->inspect();
        if (($state['available'] ?? false) !== true) {
            return [
                'requested' => true,
                'created' => false,
                'message' => $message,
                'commit' => null,
                'branch' => null,
                'files' => [],
                'warning' => 'Git repository not detected; commit skipped.',
            ];
        }

        $files = $this->normalizePaths($paths);
        if ($files === []) {
            return [
                'requested' => true,
                'created' => false,
                'message' => $message,
                'commit' => null,
                'branch' => (string) ($state['branch'] ?? ''),
                'files' => [],
                'warning' => 'No safe changed files were available to commit.',
            ];
        }

        $add = $this->run(array_merge(['add', '--'], $files), allowFailure: true);
        if (!$add['ok']) {
            return [
                'requested' => true,
                'created' => false,
                'message' => $message,
                'commit' => null,
                'branch' => (string) ($state['branch'] ?? ''),
                'files' => $files,
                'warning' => $this->trimOrNull($add['stderr']) ?? 'Git add failed; commit skipped.',
            ];
        }

        $staged = $this->normalizePaths($this->lines($this->run(['diff', '--cached', '--name-only', '--'], allowFailure: true)['stdout']));
        $staged = array_values(array_intersect($staged, $files));
        sort($staged);

        if ($staged === []) {
            return [
                'requested' => true,
                'created' => false,
                'message' => $message,
                'commit' => null,
                'branch' => (string) ($state['branch'] ?? ''),
                'files' => $files,
                'warning' => 'No staged generate changes were available to commit.',
            ];
        }

        $commit = $this->run(['commit', '-m', $message], allowFailure: true);
        if (!$commit['ok']) {
            return [
                'requested' => true,
                'created' => false,
                'message' => $message,
                'commit' => null,
                'branch' => (string) ($state['branch'] ?? ''),
                'files' => $staged,
                'warning' => $this->trimOrNull($commit['stderr']) ?? $this->trimOrNull($commit['stdout']) ?? 'Git commit failed after generation.',
            ];
        }

        return [
            'requested' => true,
            'created' => true,
            'message' => $message,
            'commit' => $this->trimOrNull($this->run(['rev-parse', 'HEAD'], allowFailure: true)['stdout']),
            'branch' => $this->trimOrNull($this->run(['symbolic-ref', '--quiet', '--short', 'HEAD'], allowFailure: true)['stdout']),
            'files' => $staged,
        ];
    }

    /**
     * @param array<int,string> $paths
     * @return array<int,string>
     */
    private function normalizePaths(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(array_map(
            static fn(string $path): string => trim(str_replace('\\', '/', $path)),
            $paths,
        ))));
        sort($paths);

        return $paths;
    }

    /**
     * @return array<int,string>
     */
    private function safetyRelevantPaths(array $paths): array
    {
        return array_values(array_filter(
            $this->normalizePaths($paths),
            fn(string $path): bool => !$this->isInternalArtifactPath($path),
        ));
    }

    private function isInternalArtifactPath(string $path): bool
    {
        foreach ([
            '.foundry/cache/',
            '.foundry/diffs/',
            '.foundry/plans/',
            '.foundry/snapshots/',
            'app/.foundry/',
            'app/generated/',
        ] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{path:string,index_status:string,worktree_status:string,changed:bool,staged:bool,unstaged:bool,untracked:bool}
     */
    private function parseStatusLine(string $line): ?array
    {
        if ($line === '' || strlen($line) < 3) {
            return null;
        }

        $indexStatus = $line[0];
        $worktreeStatus = $line[1];
        $path = trim(substr($line, 3));

        if ($indexStatus === '?' && $worktreeStatus === '?') {
            $indexStatus = ' ';
            $worktreeStatus = '?';
        }

        if (str_contains($path, ' -> ')) {
            $parts = explode(' -> ', $path);
            $path = trim((string) end($parts));
        }

        if ($path === '') {
            return null;
        }

        return [
            'path' => $path,
            'index_status' => $indexStatus,
            'worktree_status' => $worktreeStatus,
            'changed' => $indexStatus !== ' ' || $worktreeStatus !== ' ',
            'staged' => $indexStatus !== ' ',
            'unstaged' => $worktreeStatus !== ' ' && $worktreeStatus !== '?',
            'untracked' => $worktreeStatus === '?',
        ];
    }

    /**
     * @param array<string,mixed> $status
     */
    private function statusLabel(array $status): string
    {
        $untracked = (bool) ($status['untracked'] ?? false);
        $staged = (bool) ($status['staged'] ?? false);
        $unstaged = (bool) ($status['unstaged'] ?? false);

        return match (true) {
            $untracked => 'untracked',
            $staged && $unstaged => 'staged+unstaged',
            $staged => 'staged',
            $unstaged => 'unstaged',
            default => 'clean',
        };
    }

    /**
     * @param array<int,string> $args
     * @return array{ok:bool,stdout:string,stderr:string,status:int}
     */
    private function run(array $args, bool $allowFailure = false): array
    {
        $command = array_merge(['git', '-C', $this->workingDirectory], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [
                'ok' => false,
                'stdout' => '',
                'stderr' => 'Unable to execute git command.',
                'status' => 1,
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [
            'ok' => $allowFailure ? $status === 0 : $status === 0,
            'stdout' => is_string($stdout) ? rtrim($stdout, "\r\n") : '',
            'stderr' => is_string($stderr) ? rtrim($stderr, "\r\n") : '',
            'status' => is_int($status) ? $status : 1,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function lines(string $stdout): array
    {
        if (trim($stdout) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $line): string => rtrim($line, "\r"),
            preg_split('/\R/', $stdout) ?: [],
        ), static fn(string $line): bool => $line !== ''));
    }

    private function trimOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
