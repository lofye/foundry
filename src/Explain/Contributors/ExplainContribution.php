<?php
declare(strict_types=1);

namespace Foundry\Explain\Contributors;

use Foundry\Explain\ExplainSection;

final readonly class ExplainContribution
{
    /**
     * @param array<int,ExplainSection|array<string,mixed>> $sections
     * @param array<int,string> $relatedCommands
     * @param array<int,array<string,mixed>> $relatedDocs
     */
    public function __construct(
        public array $sections = [],
        public array $relatedCommands = [],
        public array $relatedDocs = [],
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            sections: array_values(array_filter(
                array_map(
                    static fn (mixed $section): ?ExplainSection => $section instanceof ExplainSection
                        ? $section
                        : (is_array($section) ? ExplainSection::fromArray($section) : null),
                    (array) ($payload['sections'] ?? []),
                ),
            )),
            relatedCommands: array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($payload['related_commands'] ?? $payload['relatedCommands'] ?? []),
            ), static fn (string $value): bool => $value !== '')),
            relatedDocs: array_values(array_filter(
                (array) ($payload['related_docs'] ?? $payload['relatedDocs'] ?? []),
                'is_array',
            )),
        );
    }
}
