<?php
declare(strict_types=1);

namespace Foundry\Explain;

use Foundry\Compiler\ApplicationGraph;

final class ExplainContext
{
    /**
     * @var array<string,mixed>
     */
    private array $data = [];

    public function __construct(
        public readonly ApplicationGraph $graph,
        public readonly ExplainArtifactCatalog $artifacts,
        public readonly ExplainSubject $subject,
        public readonly string $commandPrefix,
    ) {
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
