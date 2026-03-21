<?php
declare(strict_types=1);

namespace Foundry\Explain;

final readonly class ExplainTarget
{
    /**
     * @var array<int,string>
     */
    public const SUPPORTED_KINDS = [
        'feature',
        'route',
        'event',
        'workflow',
        'command',
        'job',
        'schema',
        'extension',
        'pipeline_stage',
        'guard',
        'permission',
    ];

    public function __construct(
        public string $raw,
        public ?string $kind,
        public string $selector,
    ) {
    }

    public static function parse(string $raw, ?string $kindOverride = null): self
    {
        $normalized = trim($raw);
        $kind = null;
        $selector = $normalized;

        if (preg_match('/^([a-z_]+):(.*)$/', $normalized, $matches) === 1) {
            $candidateKind = strtolower(trim((string) ($matches[1] ?? '')));
            if (in_array($candidateKind, self::SUPPORTED_KINDS, true)) {
                $kind = $candidateKind;
                $selector = trim((string) ($matches[2] ?? ''));
            }
        }

        if ($kind === null && $kindOverride !== null && $kindOverride !== '') {
            $kind = strtolower(trim($kindOverride));
        }

        return new self($normalized, $kind, $selector);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'kind' => $this->kind,
            'selector' => $this->selector,
        ];
    }
}
