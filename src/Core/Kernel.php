<?php

declare(strict_types=1);

namespace Foundry\Core;

use Foundry\Http\HttpKernel;
use Foundry\Http\RequestContext;

final class Kernel
{
    public function __construct(
        private readonly RuntimeMode $mode,
        private readonly ?HttpKernel $httpKernel = null,
    ) {}

    /**
     * @return array{status:int,headers:array<string,string>,body:array<string,mixed>}
     */
    public function handleHttp(RequestContext $request): array
    {
        if ($this->mode !== RuntimeMode::Http && $this->mode !== RuntimeMode::Test) {
            return [
                'status' => 500,
                'headers' => ['content-type' => 'application/json'],
                'body' => [
                    'error' => [
                        'code' => 'KERNEL_MODE_ERROR',
                        'category' => 'runtime',
                        'message' => 'Kernel is not running in HTTP mode.',
                        'details' => ['mode' => $this->mode->value],
                    ],
                ],
            ];
        }

        if ($this->httpKernel === null) {
            return [
                'status' => 500,
                'headers' => ['content-type' => 'application/json'],
                'body' => [
                    'error' => [
                        'code' => 'HTTP_KERNEL_MISSING',
                        'category' => 'runtime',
                        'message' => 'HTTP kernel missing.',
                        'details' => [],
                    ],
                ],
            ];
        }

        return $this->httpKernel->handle($request);
    }
}
