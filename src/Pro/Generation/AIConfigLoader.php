<?php
declare(strict_types=1);

namespace Foundry\Pro\Generation;

use Foundry\Config\ConfigCompatibilityNormalizer;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class AIConfigLoader
{
    /**
     * @return array<string,mixed>
     */
    public function load(Paths $paths): array
    {
        $path = $paths->join('config/ai.php');
        if (!is_file($path)) {
            return ['default' => 'static'];
        }

        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new FoundryError(
                'AI_CONFIG_INVALID',
                'validation',
                ['path' => $path],
                'AI config must return an array.',
            );
        }

        $normalized = (new ConfigCompatibilityNormalizer())->normalize('config.ai', $loaded, $path);

        return is_array($normalized['normalized'] ?? null)
            ? $normalized['normalized']
            : ['default' => 'static'];
    }
}
