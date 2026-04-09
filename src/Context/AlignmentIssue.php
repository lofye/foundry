<?php

declare(strict_types=1);

namespace Foundry\Context;

final readonly class AlignmentIssue
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $spec_section = null,
        public ?string $state_section = null,
        public bool $decision_reference_found = false,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'spec_section' => $this->spec_section,
            'state_section' => $this->state_section,
            'decision_reference_found' => $this->decision_reference_found,
        ];
    }
}
