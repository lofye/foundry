<?php
declare(strict_types=1);

namespace Forge\Config;

use Forge\Support\Arr;

final class ConfigRepository
{
    /**
     * @var array<string,mixed>
     */
    private array $config = [];

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &$this->config;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->config;
    }
}
