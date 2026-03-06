<?php
declare(strict_types=1);

namespace Foundry\Http;

final class RouteCollection
{
    /**
     * @param array<int,Route> $routes
     */
    public function __construct(private readonly array $routes)
    {
    }

    /**
     * @return array<int,Route>
     */
    public function all(): array
    {
        return $this->routes;
    }
}
