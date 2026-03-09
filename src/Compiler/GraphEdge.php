<?php
declare(strict_types=1);

namespace Foundry\Compiler;

final readonly class GraphEdge
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $from,
        public string $to,
        public array $payload = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'from' => $this->from,
            'to' => $this->to,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function make(string $type, string $from, string $to, array $payload = []): self
    {
        $id = 'edge:' . $type . ':' . $from . '->' . $to;
        if ($payload !== []) {
            ksort($payload);
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $id .= ':' . substr(hash('sha256', is_string($encoded) ? $encoded : serialize($payload)), 0, 12);
        }

        return new self($id, $type, $from, $to, $payload);
    }
}
