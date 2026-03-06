<?php
declare(strict_types=1);

namespace Foundry\Support;

final class Paths
{
    private readonly string $normalizedProjectRoot;
    private readonly string $normalizedFrameworkRoot;

    public static function fromCwd(?string $cwd = null): self
    {
        return new self($cwd ?? getcwd() ?: '.', dirname(__DIR__, 2));
    }

    public function __construct(string $projectRoot, ?string $frameworkRoot = null)
    {
        $this->normalizedProjectRoot = rtrim($projectRoot, '/');
        $this->normalizedFrameworkRoot = rtrim($frameworkRoot ?? dirname(__DIR__, 2), '/');
    }

    public function root(): string
    {
        return $this->normalizedProjectRoot;
    }

    public function frameworkRoot(): string
    {
        return $this->normalizedFrameworkRoot;
    }

    public function app(): string
    {
        return $this->join('app');
    }

    public function features(): string
    {
        return $this->join('app/features');
    }

    public function generated(): string
    {
        return $this->join('app/generated');
    }

    public function platform(): string
    {
        return $this->join('app/platform');
    }

    public function stubs(): string
    {
        return $this->frameworkJoin('stubs');
    }

    public function examples(): string
    {
        return $this->frameworkJoin('examples');
    }

    public function join(string $relative): string
    {
        return $this->normalizedProjectRoot . '/' . ltrim($relative, '/');
    }

    public function frameworkJoin(string $relative): string
    {
        return $this->normalizedFrameworkRoot . '/' . ltrim($relative, '/');
    }
}
