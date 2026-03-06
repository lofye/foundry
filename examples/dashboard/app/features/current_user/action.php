<?php
declare(strict_types=1);

namespace App\Features\CurrentUser;

use Forge\Auth\AuthContext;
use Forge\Feature\FeatureAction;
use Forge\Feature\FeatureServices;
use Forge\Http\RequestContext;

final class Action implements FeatureAction
{
    public function handle(array , RequestContext , AuthContext , FeatureServices ): array
    {
        return [
            'ok' => true,
            'feature' => 'current_user',
        ];
    }
}
