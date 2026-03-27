<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Auth\AuthorizationEngine;
use Foundry\Auth\PermissionRegistry;
use Foundry\Feature\FeatureDefinition;
use Foundry\Http\RequestContext;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class AuthEngineEdgeTest extends TestCase
{
    public function test_authenticate_throws_when_required_but_not_authenticated(): void
    {
        $engine = new AuthorizationEngine(new PermissionRegistry(), []);

        $feature = new FeatureDefinition(
            'f',
            'http',
            'd',
            ['method' => 'GET', 'path' => '/f'],
            'in',
            'out',
            ['required' => true, 'strategies' => ['bearer'], 'permissions' => []],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            '/tmp',
        );

        $this->expectException(FoundryError::class);
        $engine->authenticate($feature, new RequestContext('GET', '/f'));
    }

    public function test_authenticate_guest_when_not_required(): void
    {
        $engine = new AuthorizationEngine(new PermissionRegistry(), []);

        $feature = new FeatureDefinition(
            'f',
            'http',
            'd',
            ['method' => 'GET', 'path' => '/f'],
            'in',
            'out',
            ['required' => false, 'strategies' => [], 'permissions' => []],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            '/tmp',
        );

        $auth = $engine->authenticate($feature, new RequestContext('GET', '/f'));
        $this->assertFalse($auth->isAuthenticated());
    }
}
