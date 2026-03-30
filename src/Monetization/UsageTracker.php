<?php

declare(strict_types=1);

namespace Foundry\Monetization;

use Foundry\Support\Json;

final class UsageTracker
{
    /**
     * @return array{enabled:bool,mode:string,path:string}
     */
    public function status(): array
    {
        $enabled = $this->enabled();

        return [
            'enabled' => $enabled,
            'mode' => $enabled ? 'local_opt_in' : 'disabled',
            'path' => $this->path(),
        ];
    }

    /**
     * @param array<string,mixed> $record
     */
    public function record(array $record): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $payload = ['tracked_at' => gmdate(DATE_ATOM)] + $record;

        return file_put_contents($path, Json::encode($payload, true) . PHP_EOL, FILE_APPEND) !== false;
    }

    public function enabled(): bool
    {
        $value = getenv('FOUNDRY_USAGE_TRACKING');
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function path(): string
    {
        $override = getenv('FOUNDRY_USAGE_LOG_PATH');
        if (is_string($override) && trim($override) !== '') {
            return $this->normalizePath($override);
        }

        $foundryHome = getenv('FOUNDRY_HOME');
        if (is_string($foundryHome) && trim($foundryHome) !== '') {
            return $this->normalizePath(rtrim($foundryHome, '/\\') . '/usage.jsonl');
        }

        $home = getenv('HOME');
        if (is_string($home) && trim($home) !== '') {
            return $this->normalizePath(rtrim($home, '/\\') . '/.foundry/usage.jsonl');
        }

        return $this->normalizePath((getcwd() ?: '.') . '/.foundry/usage.jsonl');
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }
}
