<?php

declare(strict_types=1);

namespace Foundry\Support;

final class FoundryError extends \RuntimeException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $category,
        public readonly array $details = [],
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array{error:array{code:string,category:string,message:string,details:array<string,mixed>}}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'category' => $this->category,
                'message' => $this->getMessage(),
                'details' => $this->details,
            ],
        ];
    }
}
