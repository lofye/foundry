<?php

declare(strict_types=1);

namespace Foundry\Feature;

use Foundry\Auth\AuthContext;
use Foundry\Http\RequestContext;

interface FeatureAction
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function handle(
        array $input,
        RequestContext $request,
        AuthContext $auth,
        FeatureServices $services,
    ): array;
}
