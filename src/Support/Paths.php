<?php
declare(strict_types=1);

namespace Forge\Support;

final class Paths
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public static function fromCwd(?string $cwd = null): self
    {
        return new self($cwd ?? getcwd() ?: '.');
    }

    public function root(): string
    {
        return $this->projectRoot;
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
        return $this->join('stubs');
    }

    public function examples(): string
    {
        return $this->join('examples');
    }

    public function join(string $relative): string
    {
        return rtrim($this->projectRoot, '/') . '/' . ltrim($relative, '/');
    }
}
