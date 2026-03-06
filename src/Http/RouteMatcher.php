<?php
declare(strict_types=1);

namespace Foundry\Http;

final class RouteMatcher
{
    /**
     * @return array{route:Route,request:RequestContext}|null
     */
    public function match(RouteCollection $routes, RequestContext $request): ?array
    {
        $matchedRequest = null;
        $route = array_find($routes->all(), function (Route $route) use ($request, &$matchedRequest): bool {
            if (strtoupper($route->method) !== $request->method()) {
                return false;
            }

            $params = $this->matchPath($route->path, $request->path());
            if ($params === null) {
                return false;
            }

            $matchedRequest = $request->withRouteParams($params);

            return true;
        });

        if ($route !== null && $matchedRequest !== null) {
            return [
                'route' => $route,
                'request' => $matchedRequest,
            ];
        }

        return null;
    }

    /**
     * @return array<string,string>|null
     */
    public function matchPath(string $pattern, string $actual): ?array
    {
        if ($pattern === $actual) {
            return [];
        }

        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)', $pattern);
        if ($regex === null) {
            return null;
        }

        $regex = '#^' . $regex . '$#';
        $matches = [];
        if (!preg_match($regex, $actual, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $k => $v) {
            if (is_string($k)) {
                $params[$k] = $v;
            }
        }

        return $params;
    }
}
