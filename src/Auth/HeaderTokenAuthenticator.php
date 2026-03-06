<?php
declare(strict_types=1);

namespace Forge\Auth;

use Forge\Feature\FeatureDefinition;
use Forge\Http\RequestContext;

final class HeaderTokenAuthenticator implements Authenticator
{
    public function __construct(private readonly string $headerName = 'x-user-id')
    {
    }

    public function strategy(): string
    {
        return 'bearer';
    }

    public function authenticate(RequestContext $request, FeatureDefinition $feature): AuthContext
    {
        $userId = $request->header($this->headerName);
        if ($userId === null || $userId === '') {
            return AuthContext::guest();
        }

        return new AuthContext(
            true,
            $userId,
            [],
            array_values(array_map('strval', (array) ($feature->auth['permissions'] ?? []))),
            ['source' => 'header']
        );
    }
}
