<?php

declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Support\Json;
use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class ContractsVerifier
{
    public function __construct(private readonly Paths $paths) {}

    public function verify(): VerificationResult
    {
        $errors = [];

        $dirs = glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        foreach ($dirs as $dir) {
            $feature = basename($dir);
            foreach (['input.schema.json', 'output.schema.json'] as $schemaFile) {
                $path = $dir . '/' . $schemaFile;
                if (!is_file($path)) {
                    $errors[] = "{$feature}: missing schema {$schemaFile}";
                    continue;
                }

                $json = file_get_contents($path);
                if ($json === false) {
                    $errors[] = "{$feature}: unreadable schema {$schemaFile}";
                    continue;
                }

                try {
                    Json::decodeAssoc($json);
                } catch (\Throwable) {
                    $errors[] = "{$feature}: invalid schema JSON {$schemaFile}";
                }
            }

            $jobsPath = $dir . '/jobs.yaml';
            if (is_file($jobsPath)) {
                $jobs = Yaml::parseFile($jobsPath);
                foreach ((array) ($jobs['dispatch'] ?? []) as $job) {
                    if (!is_array($job) || !isset($job['input_schema'])) {
                        $errors[] = "{$feature}: job missing input_schema";
                    }
                }
            }

            $eventsPath = $dir . '/events.yaml';
            if (is_file($eventsPath)) {
                $events = Yaml::parseFile($eventsPath);
                foreach ((array) ($events['emit'] ?? []) as $event) {
                    if (!is_array($event) || !isset($event['schema'])) {
                        $errors[] = "{$feature}: emitted event missing schema";
                    }
                }
            }
        }

        $schemaIndexPath = $this->paths->join('app/generated/schema_index.php');
        if (is_file($schemaIndexPath)) {
            /** @var mixed $schemaIndex */
            $schemaIndex = require $schemaIndexPath;
            if (!is_array($schemaIndex)) {
                $errors[] = 'schema_index.php must return array.';
            }
        }

        return new VerificationResult($errors === [], $errors);
    }
}
