<?php

declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\DB\SqlFileLoader;
use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class FeatureVerifier
{
    public function __construct(
        private readonly Paths $paths,
        private readonly SqlFileLoader $sqlLoader = new SqlFileLoader(),
    ) {}

    public function verify(string $feature): VerificationResult
    {
        $base = $this->paths->join('app/features/' . $feature);
        $errors = [];

        $requiredFiles = [
            'feature.yaml',
            'action.php',
            'input.schema.json',
            'output.schema.json',
            'context.manifest.json',
            'tests',
        ];

        foreach ($requiredFiles as $file) {
            $path = $base . '/' . $file;
            if (!file_exists($path)) {
                $errors[] = "Missing required file: {$path}";
            }
        }

        if ($errors !== []) {
            return new VerificationResult(false, $errors);
        }

        $manifest = Yaml::parseFile($base . '/feature.yaml');
        if (!isset($manifest['version']) || !is_int($manifest['version'])) {
            $errors[] = 'feature.yaml: version must be integer.';
        }

        $featureName = (string) ($manifest['feature'] ?? '');
        if ($featureName !== $feature) {
            $errors[] = 'feature.yaml: feature mismatch with folder name.';
        }

        $kind = (string) ($manifest['kind'] ?? '');
        $allowed = ['http', 'job', 'event_handler', 'scheduled', 'webhook_incoming', 'webhook_outgoing', 'ai_task'];
        if (!in_array($kind, $allowed, true)) {
            $errors[] = 'feature.yaml: invalid kind.';
        }

        if ($kind === 'http') {
            $method = (string) ($manifest['route']['method'] ?? '');
            $path = (string) ($manifest['route']['path'] ?? '');
            if ($method === '' || $path === '' || !str_starts_with($path, '/')) {
                $errors[] = 'feature.yaml: route must include method and absolute path for http features.';
            }
        }

        foreach (['input.schema.json', 'output.schema.json'] as $schemaFile) {
            $content = file_get_contents($base . '/' . $schemaFile);
            if ($content === false) {
                $errors[] = "Unable to read {$schemaFile}.";
                continue;
            }

            try {
                Json::decodeAssoc($content);
            } catch (\Throwable) {
                $errors[] = "Invalid JSON schema: {$schemaFile}";
            }
        }

        $queriesPath = $base . '/queries.sql';
        if (is_file($queriesPath)) {
            $definitions = $this->sqlLoader->load($feature, $queriesPath);
            $knownQueries = array_map(static fn($d): string => $d->name, $definitions);
            foreach ((array) ($manifest['database']['queries'] ?? []) as $query) {
                if (!in_array((string) $query, $knownQueries, true)) {
                    $errors[] = "Referenced query not found: {$query}";
                }
            }
        }

        $permissionFile = $base . '/permissions.yaml';
        if (is_file($permissionFile)) {
            $permissions = Yaml::parseFile($permissionFile);
            $known = array_values(array_map('strval', (array) ($permissions['permissions'] ?? [])));
            foreach ((array) ($manifest['auth']['permissions'] ?? []) as $perm) {
                if (!in_array((string) $perm, $known, true)) {
                    $errors[] = "Referenced permission missing: {$perm}";
                }
            }
        }

        $requiredTests = array_values(array_map('strval', (array) ($manifest['tests']['required'] ?? [])));
        foreach ($requiredTests as $testType) {
            $testPath = $base . '/tests/' . $feature . '_' . $testType . '_test.php';
            if (!is_file($testPath)) {
                $errors[] = "Missing required test file: {$testPath}";
            }
        }

        return new VerificationResult($errors === [], $errors);
    }
}
