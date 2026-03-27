<?php

declare(strict_types=1);

namespace Foundry\Cache;

use Foundry\Support\FoundryError;

final class CacheKeyBuilder
{
    /**
     * @param array<string,mixed> $params
     */
    public function build(string $template, array $params = []): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            static function (array $matches) use ($params): string {
                $key = $matches[1];
                if (!array_key_exists($key, $params)) {
                    throw new FoundryError('CACHE_KEY_PARAM_MISSING', 'validation', ['param' => $key], 'Missing cache key parameter.');
                }

                return rawurlencode((string) $params[$key]);
            },
            $template,
        );
    }
}
