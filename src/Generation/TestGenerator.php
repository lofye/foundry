<?php
declare(strict_types=1);

namespace Forge\Generation;

use Forge\Testing\AuthTestGenerator;
use Forge\Testing\ContractTestGenerator;
use Forge\Testing\FeatureTestGenerator;
use Forge\Testing\JobTestGenerator;

final class TestGenerator
{
    public function __construct(
        private readonly ContractTestGenerator $contract = new ContractTestGenerator(),
        private readonly FeatureTestGenerator $feature = new FeatureTestGenerator(),
        private readonly AuthTestGenerator $auth = new AuthTestGenerator(),
        private readonly JobTestGenerator $job = new JobTestGenerator(),
    ) {
    }

    /**
     * @param array<int,string> $required
     * @return array<int,string>
     */
    public function generate(string $featureName, string $featurePath, array $required): array
    {
        $testsPath = rtrim($featurePath, '/') . '/tests';
        if (!is_dir($testsPath)) {
            mkdir($testsPath, 0777, true);
        }

        $required = array_values(array_unique(array_map('strval', $required)));
        sort($required);

        $written = [];

        if (in_array('contract', $required, true)) {
            $path = $testsPath . '/' . $featureName . '_contract_test.php';
            file_put_contents($path, $this->contract->generate($featureName));
            $written[] = $path;
        }

        if (in_array('feature', $required, true)) {
            $path = $testsPath . '/' . $featureName . '_feature_test.php';
            file_put_contents($path, $this->feature->generate($featureName));
            $written[] = $path;
        }

        if (in_array('auth', $required, true)) {
            $path = $testsPath . '/' . $featureName . '_auth_test.php';
            file_put_contents($path, $this->auth->generate($featureName));
            $written[] = $path;
        }

        if (in_array('job', $required, true)) {
            $path = $testsPath . '/' . $featureName . '_job_test.php';
            file_put_contents($path, $this->job->generate($featureName));
            $written[] = $path;
        }

        return $written;
    }
}
