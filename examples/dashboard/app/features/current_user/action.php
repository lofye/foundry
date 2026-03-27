<?php
declare(strict_types=1);

namespace App\Features\CurrentUser;

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
            'feature' => 'current_user',
        ];
    }
}
