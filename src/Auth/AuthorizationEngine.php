<?php
declare(strict_types=1);

namespace Foundry\Auth;

use Foundry\Feature\FeatureDefinition;
use Foundry\Http\RequestContext;
use Foundry\Support\FoundryError;

final class AuthorizationEngine
{
    /**
     * @param array<string,Authenticator> $authenticators
     */
    public function __construct(
        private readonly PermissionRegistry $permissions,
        private readonly array $authenticators = [],
    ) {
    }

    public function authenticate(FeatureDefinition $feature, RequestContext $request): AuthContext
    {
        $auth = $feature->auth;
        $required = (bool) ($auth['required'] ?? false);
        $strategies = array_values(array_map('strval', (array) ($auth['strategies'] ?? [])));

        if ($strategies === []) {
            return $required ? AuthContext::guest() : AuthContext::guest();
        }

        foreach ($strategies as $strategy) {
            $authenticator = $this->authenticators[$strategy] ?? null;
            if ($authenticator === null) {
                continue;
            }

            $context = $authenticator->authenticate($request, $feature);
            if ($context->isAuthenticated()) {
                return $context;
            }
        }

        if ($required) {
            throw new FoundryError(
                'AUTHENTICATION_REQUIRED',
                'authentication',
                ['feature' => $feature->name, 'strategies' => $strategies],
                'Authentication required.'
            );
        }

        return AuthContext::guest();
    }

    public function authorize(FeatureDefinition $feature, AuthContext $auth): AuthorizationDecision
    {
        $authConfig = $feature->auth;
        $required = (bool) ($authConfig['required'] ?? false);
        if ($required && !$auth->isAuthenticated()) {
            return AuthorizationDecision::deny('authentication_required');
        }

        /** @var array<int,string> $requiredPermissions */
        $requiredPermissions = array_values(array_map('strval', (array) ($authConfig['permissions'] ?? [])));
        foreach ($requiredPermissions as $permission) {
            if (!$this->permissions->has($permission)) {
                return AuthorizationDecision::deny('unknown_permission:' . $permission);
            }

            if (!in_array($permission, $auth->permissions(), true)) {
                return AuthorizationDecision::deny('missing_permission:' . $permission);
            }
        }

        return AuthorizationDecision::allow();
    }
}
