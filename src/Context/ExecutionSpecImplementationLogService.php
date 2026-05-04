<?php

declare(strict_types=1);

namespace Foundry\Context;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ExecutionSpecImplementationLogService
{
    /**
     * @var \Closure():\DateTimeImmutable
     */
    private readonly \Closure $nowProvider;

    public function __construct(
        private readonly Paths $paths,
        ?\Closure $nowProvider = null,
    ) {
        $this->nowProvider = $nowProvider ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    public function recordIfEligible(ExecutionSpec $executionSpec): ?string
    {
        $suggestion = $this->suggestedEntry($executionSpec);
        if ($suggestion === null) {
            return null;
        }

        $specReference = (string) $suggestion['spec_ref'];
        $relativeLogPath = (string) $suggestion['log_path'];
        $contents = $this->readLogContents($relativeLogPath);

        if ($this->hasEntry($contents, $specReference)) {
            return null;
        }

        $entry = (string) $suggestion['entry'];

        $updatedContents = $contents === ''
            ? $entry
            : rtrim($contents, "\n") . "\n\n" . $entry;

        $directory = dirname($this->absoluteLogPath($relativeLogPath));
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw $this->writeFailure('Could not create the execution spec implementation log directory.', $relativeLogPath);
        }

        if (file_put_contents($this->absoluteLogPath($relativeLogPath), $updatedContents) === false) {
            throw $this->writeFailure('Could not append the required execution spec implementation log entry.', $relativeLogPath);
        }

        return 'Appended implementation log entry: ' . $relativeLogPath;
    }

    /**
     * @return array{
     *     spec_id:string,
     *     feature:string,
     *     spec_ref:string,
     *     spec_path:string,
     *     log_path:string,
     *     timestamp:string,
     *     timestamp_heading:string,
     *     spec_log_line:string,
     *     entry:string
     * }|null
     */
    public function suggestedEntry(ExecutionSpec $executionSpec): ?array
    {
        $parsedPath = ExecutionSpecFilename::parseActivePath($executionSpec->path);
        if ($parsedPath === null) {
            return null;
        }

        $specReference = $parsedPath['feature'] . '/' . $parsedPath['name'] . '.md';
        $timestamp = ($this->nowProvider)()->format('Y-m-d H:i:s O');
        $timestampHeading = '## ' . $timestamp;
        $specLogLine = '- spec: ' . $specReference;

        return [
            'spec_id' => $executionSpec->specId,
            'feature' => $parsedPath['feature'],
            'spec_ref' => $specReference,
            'spec_path' => $executionSpec->path,
            'log_path' => $this->relativeLogPath($executionSpec),
            'timestamp' => $timestamp,
            'timestamp_heading' => $timestampHeading,
            'spec_log_line' => $specLogLine,
            'entry' => $timestampHeading . "\n" . $specLogLine . "\n",
        ];
    }

    private function readLogContents(string $relativeLogPath): string
    {
        $path = $this->absoluteLogPath($relativeLogPath);
        if (!file_exists($path)) {
            return '';
        }

        if (is_dir($path)) {
            throw $this->writeFailure('Execution spec implementation log path must be a file.', $relativeLogPath);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw $this->writeFailure('Execution spec implementation log could not be read.', $relativeLogPath);
        }

        return $contents;
    }

    private function hasEntry(string $contents, string $specReference): bool
    {
        return preg_match('/^- spec: ' . preg_quote($specReference, '/') . '$/m', $contents) === 1;
    }

    private function relativeLogPath(?ExecutionSpec $executionSpec = null): string
    {
        if ($executionSpec !== null && str_starts_with($executionSpec->path, 'Features/')) {
            return 'Features/implementation.log';
        }

        if (is_file($this->paths->join('Features/implementation.log')) || is_dir($this->paths->join('Features'))) {
            return 'Features/implementation.log';
        }

        return 'docs/features/implementation-log.md';
    }

    private function absoluteLogPath(string $relativeLogPath): string
    {
        return $this->paths->join($relativeLogPath);
    }

    private function writeFailure(string $message, string $relativeLogPath): FoundryError
    {
        return new FoundryError(
            'EXECUTION_SPEC_IMPLEMENTATION_LOG_WRITE_FAILED',
            'filesystem',
            ['path' => $relativeLogPath],
            $message,
        );
    }
}
