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

    public function bootstrap(): string
    {
        return $this->join('bootstrap');
    }

    public function config(): string
    {
        return $this->join('config');
    }

    public function foundryConfig(): string
    {
        return $this->join('config/foundry');
    }

    public function database(): string
    {
        return $this->join('database');
    }

    public function migrations(): string
    {
        return $this->join('database/migrations');
    }

    public function lang(): string
    {
        return $this->join('lang');
    }

    public function public(): string
    {
        return $this->join('public');
    }

    public function storage(): string
    {
        return $this->join('storage');
    }

    public function logs(): string
    {
        return $this->join('storage/logs');
    }

    public function tmp(): string
    {
        return $this->join('storage/tmp');
    }

    public function storageFiles(): string
    {
        return $this->join('storage/files');
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
