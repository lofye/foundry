<?php

declare(strict_types=1);

namespace Foundry\Auth;

use Foundry\Feature\FeatureDefinition;
use Foundry\Http\RequestContext;

interface Authenticator
{
    public function strategy(): string;

    public function authenticate(RequestContext $request, FeatureDefinition $feature): AuthContext;
}
