<?php
declare(strict_types=1);

namespace Foundry\Verification;

use Foundry\Support\Paths;

final class MigrationsVerifier
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function verify(): VerificationResult
    {
        $errors = [];
        $warnings = [];

        $dir = $this->paths->join('database/migrations');
        if (!is_dir($dir)) {
            return new VerificationResult(true, [], []);
        }

        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $ordered = $files;

        usort(
            $ordered,
            static fn (string $a, string $b): int => strcmp(basename($a), basename($b))
        );

        if ($ordered !== $files) {
            $errors[] = 'Migration filenames are not in deterministic order.';
        }

        foreach ($files as $file) {
            $sql = file_get_contents($file) ?: '';
            if (preg_match('/\bDROP\s+TABLE\b/i', $sql)) {
                $warnings[] = basename($file) . ': contains DROP TABLE';
            }

            if (preg_match('/\bDELETE\s+FROM\b(?![^;]*\bWHERE\b)/is', $sql)) {
                $warnings[] = basename($file) . ': contains DELETE without WHERE';
            }
        }

        return new VerificationResult($errors === [], $errors, $warnings);
    }
}
