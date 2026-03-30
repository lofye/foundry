<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Monetization\FeatureFlags;
use Foundry\Monetization\MonetizationService;
use Foundry\Pro\FeatureGate;
use Foundry\Pro\LicenseStore;
use Foundry\Pro\LicenseValidator;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class ProLicenseTest extends TestCase
{
    private ?string $previousFoundryHome = null;
    private ?string $previousLicensePath = null;
    private ?string $previousLicenseKey = null;
    private ?string $previousProLicenseKey = null;
    private ?string $previousUsageTracking = null;
    private ?string $previousUsageLogPath = null;
    private ?string $previousValidationUrl = null;
    private string $tempHome;

    protected function setUp(): void
    {
        $this->previousFoundryHome = getenv('FOUNDRY_HOME') !== false ? (string) getenv('FOUNDRY_HOME') : null;
        $this->previousLicensePath = getenv('FOUNDRY_LICENSE_PATH') !== false ? (string) getenv('FOUNDRY_LICENSE_PATH') : null;
        $this->previousLicenseKey = getenv('FOUNDRY_LICENSE_KEY') !== false ? (string) getenv('FOUNDRY_LICENSE_KEY') : null;
        $this->previousProLicenseKey = getenv('FOUNDRY_PRO_LICENSE_KEY') !== false ? (string) getenv('FOUNDRY_PRO_LICENSE_KEY') : null;
        $this->previousUsageTracking = getenv('FOUNDRY_USAGE_TRACKING') !== false ? (string) getenv('FOUNDRY_USAGE_TRACKING') : null;
        $this->previousUsageLogPath = getenv('FOUNDRY_USAGE_LOG_PATH') !== false ? (string) getenv('FOUNDRY_USAGE_LOG_PATH') : null;
        $this->previousValidationUrl = getenv('FOUNDRY_LICENSE_VALIDATION_URL') !== false ? (string) getenv('FOUNDRY_LICENSE_VALIDATION_URL') : null;

        $this->tempHome = sys_get_temp_dir() . '/foundry-pro-license-' . bin2hex(random_bytes(6));
        mkdir($this->tempHome, 0777, true);

        putenv('FOUNDRY_HOME=' . $this->tempHome);
        putenv('FOUNDRY_LICENSE_PATH');
        putenv('FOUNDRY_LICENSE_KEY');
        putenv('FOUNDRY_PRO_LICENSE_KEY');
        putenv('FOUNDRY_USAGE_TRACKING');
        putenv('FOUNDRY_USAGE_LOG_PATH');
        putenv('FOUNDRY_LICENSE_VALIDATION_URL');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('FOUNDRY_HOME', $this->previousFoundryHome);
        $this->restoreEnv('FOUNDRY_LICENSE_PATH', $this->previousLicensePath);
        $this->restoreEnv('FOUNDRY_LICENSE_KEY', $this->previousLicenseKey);
        $this->restoreEnv('FOUNDRY_PRO_LICENSE_KEY', $this->previousProLicenseKey);
        $this->restoreEnv('FOUNDRY_USAGE_TRACKING', $this->previousUsageTracking);
        $this->restoreEnv('FOUNDRY_USAGE_LOG_PATH', $this->previousUsageLogPath);
        $this->restoreEnv('FOUNDRY_LICENSE_VALIDATION_URL', $this->previousValidationUrl);
        $this->deleteDirectory($this->tempHome);
    }

    public function test_validator_accepts_valid_license_key_and_exposes_pro_features(): void
    {
        $validator = new LicenseValidator();
        $license = $validator->validate("  \n" . strtolower($this->validKey()) . "\n");

        $this->assertSame('foundry-pro', $license['product']);
        $this->assertSame('pro', $license['plan']);
        $this->assertSame(FeatureFlags::pro(), LicenseValidator::FEATURES);
        $this->assertSame(LicenseValidator::FEATURES, $license['features']);
        $this->assertSame('...' . substr($this->validKey(), -4), $license['key_hint']);
    }

    public function test_validator_rejects_invalid_license_key(): void
    {
        $validator = new LicenseValidator();

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('checksum');

        $validator->validate('FPRO-ABCD-EFGH-IJKL-MNOP-00000000');
    }

    public function test_store_reports_missing_valid_and_invalid_statuses(): void
    {
        $store = new LicenseStore();

        $missing = $store->status();
        $this->assertFalse($missing['valid']);
        $this->assertSame('missing', $missing['status']);

        $enabled = $store->enable($this->validKey());
        $this->assertTrue($enabled['valid']);
        $this->assertSame('enabled', $enabled['status']);
        $this->assertFileExists($store->path());

        file_put_contents($store->path(), '{"license_key":"bad"}');

        $invalid = $store->status();
        $this->assertFalse($invalid['valid']);
        $this->assertSame('invalid', $invalid['status']);
    }

    public function test_feature_gate_blocks_when_license_is_missing(): void
    {
        $gate = new FeatureGate(new LicenseStore());

        $this->expectException(FoundryError::class);
        $this->expectExceptionMessage('Run `foundry license:activate <license-key>`');

        $gate->require('explain', [FeatureFlags::PRO_EXPLAIN_PLUS]);
    }

    public function test_feature_gate_accepts_enabled_license(): void
    {
        $store = new LicenseStore();
        $store->enable($this->validKey());

        $license = (new FeatureGate($store))->require('trace', [FeatureFlags::PRO_TRACE]);

        $this->assertTrue($license['valid']);
        $this->assertContains(FeatureFlags::PRO_TRACE, $license['features']);
    }

    public function test_monetization_service_prefers_environment_license_key(): void
    {
        putenv('FOUNDRY_LICENSE_KEY=' . $this->validKey());

        $status = (new MonetizationService(new LicenseStore()))->status();

        $this->assertTrue($status['valid']);
        $this->assertSame('environment', $status['source']);
        $this->assertContains(FeatureFlags::PRO_GENERATE, $status['feature_flags']);
    }

    public function test_monetization_service_can_deactivate_local_license_while_environment_source_remains(): void
    {
        $store = new LicenseStore();
        $store->enable($this->validKey());
        putenv('FOUNDRY_LICENSE_KEY=' . $this->validKey());

        $status = (new MonetizationService($store))->deactivate();

        $this->assertFalse(is_file($store->path()));
        $this->assertTrue($status['valid']);
        $this->assertSame('environment', $status['source']);
        $this->assertStringContainsString('Environment-based licensing remains active', (string) $status['message']);
    }

    public function test_monetization_service_records_usage_only_when_opted_in(): void
    {
        $store = new LicenseStore();
        $store->enable($this->validKey());
        $usageLog = $this->tempHome . '/usage.jsonl';
        putenv('FOUNDRY_USAGE_LOG_PATH=' . $usageLog);

        (new MonetizationService($store))->require('trace', [FeatureFlags::PRO_TRACE]);
        $this->assertFileDoesNotExist($usageLog);

        putenv('FOUNDRY_USAGE_TRACKING=1');

        (new MonetizationService($store))->require('trace', [FeatureFlags::PRO_TRACE]);

        $this->assertFileExists($usageLog);
        $contents = file_get_contents($usageLog);
        $this->assertIsString($contents);
        $this->assertStringContainsString(FeatureFlags::PRO_TRACE, $contents);
    }

    public function test_activation_can_store_optional_remote_validation_metadata(): void
    {
        $validationFixture = $this->tempHome . '/remote-validation.json';
        file_put_contents($validationFixture, json_encode([
            'valid' => true,
            'message' => 'Validated by optional remote service.',
            'plan' => 'pro',
            'feature_flags' => [
                FeatureFlags::PRO_GENERATE,
                FeatureFlags::PRO_EXPLAIN_PLUS,
            ],
        ], JSON_THROW_ON_ERROR));
        putenv('FOUNDRY_LICENSE_VALIDATION_URL=file://' . $validationFixture);

        $status = (new MonetizationService(new LicenseStore()))->activate($this->validKey());

        $this->assertTrue($status['valid']);
        $this->assertSame('file://remote-validation.json', $status['remote_validation']['endpoint']);
        $this->assertSame(
            [FeatureFlags::PRO_GENERATE, FeatureFlags::PRO_EXPLAIN_PLUS],
            $status['feature_flags'],
        );
    }

    private function validKey(): string
    {
        $body = 'FPRO-ABCD-EFGH-IJKL-MNOP';

        return $body . '-' . strtoupper(substr(hash('sha256', 'foundry-pro:' . $body), 0, 8));
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->deleteDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
