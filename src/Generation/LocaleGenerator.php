<?php
declare(strict_types=1);

namespace Foundry\Generation;

use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class LocaleGenerator
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(string $locale, bool $force = false): array
    {
        $locale = strtolower(trim($locale));
        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale)) {
            throw new FoundryError('LOCALE_INVALID', 'validation', ['locale' => $locale], 'Locale must match xx or xx-xx.');
        }

        $langDir = $this->paths->join('lang/' . $locale);
        if (!is_dir($langDir)) {
            mkdir($langDir, 0777, true);
        }

        $messagesPath = $langDir . '/messages.php';
        if (is_file($messagesPath) && !$force) {
            throw new FoundryError('LOCALE_EXISTS', 'io', ['path' => $messagesPath], 'Locale already exists. Use --force to overwrite.');
        }

        file_put_contents($messagesPath, <<<'PHP'
<?php

declare(strict_types=1);

return [
    'app.title' => 'Foundry App',
    'validation.required' => 'This field is required.',
    'auth.unauthorized' => 'You are not authorized.',
];
PHP);

        $definitionDir = $this->paths->join('app/definitions/locales');
        if (!is_dir($definitionDir)) {
            mkdir($definitionDir, 0777, true);
        }

        $definitionPath = $definitionDir . '/core.locale.yaml';
        $current = is_file($definitionPath) ? Yaml::parseFile($definitionPath) : ['version' => 1, 'bundle' => 'core', 'default' => 'en', 'locales' => []];
        $locales = array_values(array_unique(array_map('strval', (array) ($current['locales'] ?? []))));
        $locales[] = $locale;
        $locales = array_values(array_unique($locales));
        sort($locales);

        $current['version'] = 1;
        $current['bundle'] = 'core';
        $current['default'] = (string) ($current['default'] ?? 'en');
        $current['locales'] = $locales;
        $current['translation_paths'] = ['lang'];
        file_put_contents($definitionPath, Yaml::dump($current));

        return [
            'locale' => $locale,
            'files' => [$messagesPath, $definitionPath],
            'definition' => $definitionPath,
        ];
    }
}
