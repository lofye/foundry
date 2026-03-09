<?php
declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Compiler\CompileOptions;
use Foundry\Compiler\GraphCompiler;
use Foundry\Localization\LocaleCatalog;
use Foundry\Support\Paths;

final class LocalesVerifier
{
    public function __construct(
        private readonly GraphCompiler $compiler,
        private readonly Paths $paths,
    ) {
    }

    public function verify(?string $bundle = null): VerificationResult
    {
        $graph = $this->compiler->compile(new CompileOptions())->graph;

        $errors = [];
        $warnings = [];

        $nodes = $bundle === null || $bundle === ''
            ? $graph->nodesByType('locale_bundle')
            : ['locale_bundle:' . $bundle => $graph->node('locale_bundle:' . $bundle)];

        if ($bundle !== null && $bundle !== '' && !isset($nodes['locale_bundle:' . $bundle])) {
            $errors[] = 'Locale bundle not found in compiled graph: ' . $bundle;
        }

        $catalog = new LocaleCatalog($this->paths);

        foreach ($nodes as $node) {
            if (!$node instanceof \Foundry\Compiler\IR\GraphNode) {
                continue;
            }

            $payload = $node->payload();
            $name = (string) ($payload['bundle'] ?? '');
            $default = (string) ($payload['default'] ?? 'en');
            $locales = array_values(array_map('strval', (array) ($payload['locales'] ?? [])));
            if ($locales === []) {
                $warnings[] = sprintf('Locale bundle %s has no locales.', $name);
                continue;
            }

            if (!in_array($default, $locales, true)) {
                $errors[] = sprintf('Locale bundle %s default locale %s is not present.', $name, $default);
            }

            $defaultKeys = array_keys($catalog->load($default));
            foreach ($locales as $locale) {
                $keys = array_keys($catalog->load($locale));
                if ($keys === []) {
                    $warnings[] = sprintf('Locale %s has no translation keys.', $locale);
                    continue;
                }

                foreach ($defaultKeys as $key) {
                    if (!in_array($key, $keys, true)) {
                        $warnings[] = sprintf('Locale %s is missing key %s.', $locale, $key);
                    }
                }
            }
        }

        sort($errors);
        sort($warnings);

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
