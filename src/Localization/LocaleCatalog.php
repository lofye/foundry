<?php
declare(strict_types=1);

namespace Foundry\Localization;

use Foundry\Support\Paths;

final class LocaleCatalog
{
    /**
     * @var array<string,array<string,string>>
     */
    private array $cache = [];

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,string>
     */
    public function load(string $locale): array
    {
        $locale = strtolower($locale);
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $dir = $this->paths->join('lang/' . $locale);
        if (!is_dir($dir)) {
            $this->cache[$locale] = [];

            return $this->cache[$locale];
        }

        $messages = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            /** @var mixed $raw */
            $raw = require $file;
            if (!is_array($raw)) {
                continue;
            }

            foreach ($raw as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $messages[$key] = (string) $value;
            }
        }

        ksort($messages);
        $this->cache[$locale] = $messages;

        return $this->cache[$locale];
    }

    public function translate(string $locale, string $key, ?string $fallbackLocale = 'en'): string
    {
        $messages = $this->load($locale);
        if (isset($messages[$key])) {
            return $messages[$key];
        }

        if ($fallbackLocale !== null && $fallbackLocale !== '' && strtolower($fallbackLocale) !== strtolower($locale)) {
            $fallback = $this->load($fallbackLocale);
            if (isset($fallback[$key])) {
                return $fallback[$key];
            }
        }

        return $key;
    }
}
