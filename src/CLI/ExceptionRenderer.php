<?php

declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\Monetization\Exceptions\FeatureNotLicensed;
use Foundry\Support\FoundryError;

final class ExceptionRenderer
{
    /**
     * @return array{status:int,payload:array<string,mixed>|null,message:string|null}
     */
    public function render(\Throwable $error, bool $json): array
    {
        if ($error instanceof FeatureNotLicensed) {
            return [
                'status' => 1,
                'payload' => $error->toArray(),
                'message' => $json ? null : $this->renderFeatureNotLicensed(),
            ];
        }

        if ($error instanceof FoundryError) {
            return [
                'status' => 1,
                'payload' => $error->toArray(),
                'message' => $error->getMessage(),
            ];
        }

        $payload = [
            'error' => [
                'code' => 'CLI_UNHANDLED_EXCEPTION',
                'category' => 'runtime',
                'message' => $error->getMessage(),
                'details' => ['exception' => $error::class],
            ],
        ];

        return [
            'status' => 1,
            'payload' => $payload,
            'message' => $error->getMessage(),
        ];
    }

    private function renderFeatureNotLicensed(): string
    {
        return implode(PHP_EOL, [
            'This feature requires a license.',
            '',
            'To activate:',
            '  foundry license activate --key=YOUR_KEY',
            '',
            'Learn more:',
            '  https://foundryframework.org/pricing',
        ]);
    }
}
