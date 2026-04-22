<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Monetization\FeatureFlags;
use Foundry\Monetization\LicenseStore;
use Foundry\Monetization\LicenseValidator;
use Foundry\Monetization\MonetizationService;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class MonetizationLicenseTest extends TestCase
{
    private ?string $previousFoundryHome = null;
    private ?string $previousLicensePath = null;
    private ?string $previousLicenseKey = null;
    private ?string $previousUsageTracking = null;
    private ?string $previousUsageLogPath = null;
    private ?string $previousValidationUrl = null;
    private string $tempHome;

    protected function setUp(): void
    {
        $this->previousFoundryHome = getenv('FOUNDRY_HOME') !== false ? (string) getenv('FOUNDRY_HOME') : null;
        $this->previousLicensePath = getenv('FOUNDRY_LICENSE_PATH') !== false ? (string) getenv('FOUNDRY_LICENSE_PATH') : null;
        $this->previousLicenseKey = getenv('FOUNDRY_LICENSE_KEY') !== false ? (string) getenv('FOUNDRY_LICENSE_KEY') : null;
        $this->previousUsageTracking = getenv('FOUNDRY_USAGE_TRACKING') !== false ? (string) getenv('FOUNDRY_USAGE_TRACKING') : null;
        $this->previousUsageLogPath = getenv('FOUNDRY_USAGE_LOG_PATH') !== false ? (string) getenv('FOUNDRY_USAGE_LOG_PATH') : null;
        $this->previousValidationUrl = getenv('FOUNDRY_LICENSE_VALIDATION_URL') !== false ? (string) getenv('FOUNDRY_LICENSE_VALIDATION_URL') : null;

        $this->tempHome = sys_get_temp_dir() . '/foundry-license-' . bin2hex(random_bytes(6));
        mkdir($this->tempHome, 0777, true);

        putenv('FOUNDRY_HOME=' . $this->tempHome);
        putenv('FOUNDRY_LICENSE_PATH');
        putenv('FOUNDRY_LICENSE_KEY');
        putenv('FOUNDRY_USAGE_TRACKING');
        putenv('FOUNDRY_USAGE_LOG_PATH');
        putenv('FOUNDRY_LICENSE_VALIDATION_URL');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('FOUNDRY_HOME', $this->previousFoundryHome);
        $this->restoreEnv('FOUNDRY_LICENSE_PATH', $this->previousLicensePath);
        $this->restoreEnv('FOUNDRY_LICENSE_KEY', $this->previousLicenseKey);
        $this->restoreEnv('FOUNDRY_USAGE_TRACKING', $this->previousUsageTracking);
        $this->restoreEnv('FOUNDRY_USAGE_LOG_PATH', $this->previousUsageLogPath);
        $this->restoreEnv('FOUNDRY_LICENSE_VALIDATION_URL', $this->previousValidationUrl);
        $this->deleteDirectory($this->tempHome);
    }

    public function test_validator_accepts_valid_license_key_and_exposes_tier_metadata(): void
    {
        $validator = new LicenseValidator();
        $license = $validator->validate("  \n" . strtolower($this->validKey()) . "\n");

        $this->assertSame('foundry', $license['product']);
        $this->assertSame(FeatureFlags::TIER_PRO, $license['tier']);
        $this->assertSame(FeatureFlags::licensed(), LicenseValidator::FEATURES);
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

    public function test_capabilities_remain_enabled_without_license(): void
    {
        $service = new MonetizationService(new LicenseStore());

        $this->assertSame(FeatureFlags::TIER_FREE, $service->getTier());
        $this->assertTrue($service->isEnabled(FeatureFlags::PRO_EXPLAIN_PLUS));
        $this->assertContains('explain.advanced', $service->status()['capabilities']);
        $this->assertContains('marketplace.access', $service->status()['service_access']['unavailable']);
    }

    public function test_license_enables_service_access_without_gating_capabilities(): void
    {
        $store = new LicenseStore();
        $store->enable($this->validKey());

        $status = (new MonetizationService($store))->status();

        $this->assertTrue($status['valid']);
        $this->assertContains('marketplace.access', $status['service_access']['available']);
        $this->assertTrue((new MonetizationService($store))->isEnabled(FeatureFlags::PRO_TRACE));
    }

    public function test_monetization_service_reports_current_tier_and_enabled_features(): void
    {
        $store = new LicenseStore();
        $store->enable($this->validKey());
        $service = new MonetizationService($store);

        $this->assertSame(FeatureFlags::TIER_PRO, $service->getTier());
        $this->assertTrue($service->isEnabled(FeatureFlags::PRO_TRACE));
        $this->assertContains('trace.analysis', $service->status()['capabilities']);
    }

    public function test_monetization_service_prefers_environment_license_key(): void
    {
        putenv('FOUNDRY_LICENSE_KEY=' . $this->validKey());

        $status = (new MonetizationService(new LicenseStore()))->status();

        $this->assertTrue($status['valid']);
        $this->assertSame('environment', $status['source']);
        $this->assertContains(FeatureFlags::HOSTED_SYNC, $status['feature_flags']);
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
            'tier' => FeatureFlags::TIER_PRO,
        ], JSON_THROW_ON_ERROR));
        putenv('FOUNDRY_LICENSE_VALIDATION_URL=file://' . $validationFixture);

        $status = (new MonetizationService(new LicenseStore()))->activate($this->validKey());

        $this->assertTrue($status['valid']);
        $this->assertSame('file://remote-validation.json', $status['remote_validation']['endpoint']);
        $this->assertSame(
            FeatureFlags::enabledForTier(FeatureFlags::TIER_PRO),
            $status['feature_flags'],
        );
    }

    public function test_environment_license_key_reports_invalid_status_when_validation_fails(): void
    {
        putenv('FOUNDRY_LICENSE_KEY=FPRO-ABCD-EFGH-IJKL-MNOP-00000000');

        $status = (new MonetizationService(new LicenseStore()))->status();

        $this->assertFalse($status['valid']);
        $this->assertSame('environment', $status['source']);
        $this->assertSame('invalid', $status['status']);
    }

    public function test_deactivate_without_any_local_license_reports_clear_message(): void
    {
        $status = (new MonetizationService(new LicenseStore()))->deactivate();

        $this->assertFalse($status['deactivated']);
        $this->assertStringContainsString('No local Foundry license file was active.', (string) $status['message']);
    }

    public function test_activation_surfaces_remote_validation_rejections(): void
    {
        $validationFixture = $this->tempHome . '/remote-validation-rejected.json';
        file_put_contents($validationFixture, json_encode([
            'valid' => false,
            'message' => 'Rejected remotely.',
        ], JSON_THROW_ON_ERROR));
        putenv('FOUNDRY_LICENSE_VALIDATION_URL=file://' . $validationFixture);

        try {
            (new MonetizationService(new LicenseStore()))->activate($this->validKey());
            self::fail('Expected remote validation rejection.');
        } catch (FoundryError $error) {
            $this->assertSame('LICENSE_REMOTE_VALIDATION_FAILED', $error->errorCode);
        }
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
