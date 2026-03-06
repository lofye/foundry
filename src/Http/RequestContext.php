<?php
declare(strict_types=1);

namespace Foundry\Http;

final class RequestContext
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $routeParams
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $headers = [],
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $routeParams = [],
    ) {
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        $needle = strtolower($key);
        foreach ($this->headers as $name => $value) {
            if (strtolower($name) === $needle) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string,mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return array<string,string>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * @param array<string,string> $params
     */
    public function withRouteParams(array $params): self
    {
        return new self($this->method, $this->path, $this->headers, $this->query, $this->body, $params);
    }

    /**
     * @return array<string,mixed>
     */
    public function input(): array
    {
        return array_merge($this->query, $this->body, $this->routeParams);
    }
}
