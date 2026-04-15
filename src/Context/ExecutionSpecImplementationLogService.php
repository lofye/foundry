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
        $parsedPath = ExecutionSpecFilename::parseActivePath($executionSpec->path);
        if ($parsedPath === null) {
            return null;
        }

        $specReference = $parsedPath['feature'] . '/' . $parsedPath['name'] . '.md';
        $contents = $this->readLogContents();

        if ($this->hasEntry($contents, $specReference)) {
            return null;
        }

        $timestamp = ($this->nowProvider)()->format('Y-m-d H:i:s O');
        $entry = implode("\n", [
            '## ' . $timestamp,
            '- spec: ' . $specReference,
        ]);

        $updatedContents = $contents === ''
            ? $entry . "\n"
            : rtrim($contents, "\n") . "\n\n" . $entry . "\n";

        $directory = dirname($this->absoluteLogPath());
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw $this->writeFailure('Could not create the execution spec implementation log directory.');
        }

        if (file_put_contents($this->absoluteLogPath(), $updatedContents) === false) {
            throw $this->writeFailure('Could not append the required execution spec implementation log entry.');
        }

        return 'Appended implementation log entry: ' . $this->relativeLogPath();
    }

    private function readLogContents(): string
    {
        $path = $this->absoluteLogPath();
        if (!file_exists($path)) {
            return '';
        }

        if (is_dir($path)) {
            throw $this->writeFailure('Execution spec implementation log path must be a file.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw $this->writeFailure('Execution spec implementation log could not be read.');
        }

        return $contents;
    }

    private function hasEntry(string $contents, string $specReference): bool
    {
        return preg_match('/^- spec: ' . preg_quote($specReference, '/') . '$/m', $contents) === 1;
    }

    private function relativeLogPath(): string
    {
        return 'docs/specs/implementation-log.md';
    }

    private function absoluteLogPath(): string
    {
        return $this->paths->join($this->relativeLogPath());
    }

    private function writeFailure(string $message): FoundryError
    {
        return new FoundryError(
            'EXECUTION_SPEC_IMPLEMENTATION_LOG_WRITE_FAILED',
            'filesystem',
            ['path' => $this->relativeLogPath()],
            $message,
        );
    }
}
