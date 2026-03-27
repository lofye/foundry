<?php

declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Support\Paths;
use Foundry\Support\Yaml;

final class JobsVerifier
{
    public function __construct(private readonly Paths $paths) {}

    public function verify(): VerificationResult
    {
        $errors = [];
        $warnings = [];

        foreach (glob($this->paths->features() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $feature = basename($dir);
            $jobsPath = $dir . '/jobs.yaml';
            if (!is_file($jobsPath)) {
                continue;
            }

            $jobs = Yaml::parseFile($jobsPath);
            foreach ((array) ($jobs['dispatch'] ?? []) as $jobDef) {
                if (!is_array($jobDef)) {
                    continue;
                }

                $name = (string) ($jobDef['name'] ?? '');
                if ($name === '') {
                    $errors[] = "{$feature}: job name missing";
                    continue;
                }

                if (!isset($jobDef['input_schema']) || !is_array($jobDef['input_schema'])) {
                    $errors[] = "{$feature}: job {$name} missing payload schema";
                }

                $retry = (array) ($jobDef['retry'] ?? []);
                if (!isset($retry['max_attempts']) || (int) $retry['max_attempts'] < 1) {
                    $errors[] = "{$feature}: job {$name} invalid retry.max_attempts";
                }

                if (!isset($retry['backoff_seconds']) || !is_array($retry['backoff_seconds']) || $retry['backoff_seconds'] === []) {
                    $errors[] = "{$feature}: job {$name} invalid retry.backoff_seconds";
                }

                $queue = (string) ($jobDef['queue'] ?? '');
                if ($queue === '') {
                    $errors[] = "{$feature}: job {$name} queue name required";
                }

                $timeout = (int) ($jobDef['timeout_seconds'] ?? 0);
                if ($timeout <= 0) {
                    $errors[] = "{$feature}: job {$name} timeout_seconds must be > 0";
                }

                if (!isset($jobDef['idempotency_key'])) {
                    $warnings[] = "{$feature}: job {$name} missing idempotency_key";
                }
            }
        }

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
