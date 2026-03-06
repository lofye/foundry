<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Http\RequestContext;
use Foundry\Http\Route;
use Foundry\Http\RouteCollection;
use Foundry\Http\RouteMatcher;
use PHPUnit\Framework\TestCase;

final class RouteMatcherTest extends TestCase
{
    public function test_matches_route_with_params(): void
    {
        $routes = new RouteCollection([
            new Route('GET', '/posts/{id}', 'view_post', 'http', 'input.json', 'output.json'),
        ]);

        $match = (new RouteMatcher())->match($routes, new RequestContext('GET', '/posts/123'));

        $this->assertNotNull($match);
        $this->assertSame('view_post', $match['route']->feature);
        $this->assertSame(['id' => '123'], $match['request']->routeParams());
    }

    public function test_returns_null_when_no_route_matches(): void
    {
        $routes = new RouteCollection([
            new Route('GET', '/a', 'a', 'http', 'i', 'o'),
        ]);

        $match = (new RouteMatcher())->match($routes, new RequestContext('POST', '/a'));
        $this->assertNull($match);
    }
}
