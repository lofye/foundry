<?php
declare(strict_types=1);

namespace App\Features\ExtractSummary;

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;

final class Action implements FeatureAction
{
    #[\Override]
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        return [
            'ok' => true,
            'feature' => 'extract_summary',
        ];
    }
}
