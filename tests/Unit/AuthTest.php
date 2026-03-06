<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Auth\AuthorizationEngine;
use Foundry\Auth\HeaderTokenAuthenticator;
use Foundry\Auth\PermissionRegistry;
use Foundry\Feature\FeatureDefinition;
use Foundry\Http\RequestContext;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    public function test_authenticate_and_authorize_success(): void
    {
        $permissions = new PermissionRegistry();
        $permissions->register('posts.create');

        $engine = new AuthorizationEngine($permissions, ['bearer' => new HeaderTokenAuthenticator('x-user-id')]);

        $feature = new FeatureDefinition(
            'publish_post',
            'http',
            'desc',
            ['method' => 'POST', 'path' => '/posts'],
            'in',
            'out',
            ['required' => true, 'strategies' => ['bearer'], 'permissions' => ['posts.create']],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            '/tmp'
        );

        $auth = $engine->authenticate($feature, new RequestContext('POST', '/posts', ['x-user-id' => 'u-1']));
        $decision = $engine->authorize($feature, $auth);

        $this->assertTrue($auth->isAuthenticated());
        $this->assertTrue($decision->allowed);
    }

    public function test_authorize_denies_when_permission_missing(): void
    {
        $permissions = new PermissionRegistry();
        $permissions->register('posts.create');

        $engine = new AuthorizationEngine($permissions, []);

        $feature = new FeatureDefinition(
            'publish_post',
            'http',
            'desc',
            ['method' => 'POST', 'path' => '/posts'],
            'in',
            'out',
            ['required' => false, 'permissions' => ['posts.publish']],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            '/tmp'
        );

        $decision = $engine->authorize($feature, \Foundry\Auth\AuthContext::guest());
        $this->assertFalse($decision->allowed);
    }
}
