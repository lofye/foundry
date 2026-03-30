<?php

declare(strict_types=1);

namespace Foundry\Monetization\Exceptions;

final class FeatureNotLicensed extends \RuntimeException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly string $feature,
        public readonly string $command,
        public readonly array $details = [],
    ) {
        parent::__construct('This feature requires a license.');
    }

    /**
     * @return array{error:array{code:string,category:string,message:string,details:array<string,mixed>}}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => 'FEATURE_NOT_LICENSED',
                'category' => 'authorization',
                'message' => $this->getMessage(),
                'details' => array_merge(
                    [
                        'feature' => $this->feature,
                        'command' => $this->command,
                    ],
                    $this->details,
                ),
            ],
        ];
    }
}
