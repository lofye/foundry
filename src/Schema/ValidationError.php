<?php
declare(strict_types=1);

namespace Foundry\Schema;

final readonly class ValidationError
{
    public function __construct(
        public readonly string $path,
        public readonly string $message,
        public readonly ?string $expected = null,
        public readonly ?string $actual = null,
        public readonly ?string $suggestedFix = null,
    ) {
    }

    /**
     * @return array{path:string,message:string,expected:?string,actual:?string,suggested_fix:?string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'message' => $this->message,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'suggested_fix' => $this->suggestedFix,
        ];
    }
}
