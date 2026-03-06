<?php
declare(strict_types=1);

namespace Forge\Auth;

use Forge\Feature\FeatureDefinition;
use Forge\Http\RequestContext;

interface Authenticator
{
    public function strategy(): string;

    public function authenticate(RequestContext $request, FeatureDefinition $feature): AuthContext;
}
