<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Pro\FeatureGate;
use Foundry\Pro\LicenseStore;
use Foundry\Pro\LicenseValidator;
use Foundry\Support\FoundryError;
use PHPUnit\Framework\TestCase;

final class ProLicenseTest extends TestCase
{
    private ?string $previousFoundryHome = null;
    private ?string $previousLicensePath = null;
    private string $tempHome;

    protected function setUp(): void
    {
        $this->previousFoundryHome = getenv('FOUNDRY_HOME') !== false ? (string) getenv('FOUNDRY_HOME') : null;
        $this->previousLicensePath = getenv('FOUNDRY_LICENSE_PATH') !== false ? (string) getenv('FOUNDRY_LICENSE_PATH') : null;

        $this->tempHome = sys_get_temp_dir() . '/foundry-pro-license-' . bin2hex(random_bytes(6));
        mkdir($this->tempHome, 0777, true);

        putenv('FOUNDRY_HOME=' . $this->tempHome);
        putenv('FOUNDRY_LICENSE_PATH');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('FOUNDRY_HOME', $this->previousFoundryHome);
        $this->restoreEnv('FOUNDRY_LICENSE_PATH', $this->previousLicensePath);
        $this->deleteDirectory($this->tempHome);
    }

    public function test_validator_accepts_valid_license_key_and_exposes_pro_features(): void
    {
        $validator = new LicenseValidator();
        $license = $validator->validate("  \n" . strtolower($this->validKey()) . "\n");

        $this->assertSame('foundry-pro', $license['product']);
        $this->assertSame('pro', $license['plan']);
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
        $this->expectExceptionMessage('Run `foundry pro enable <license-key>`');

        $gate->require('explain', ['architecture_explanation']);
    }

    public function test_feature_gate_accepts_enabled_license(): void
    {
        $store = new LicenseStore();
        $store->enable($this->validKey());

        $license = (new FeatureGate($store))->require('trace', ['trace_analysis']);

        $this->assertTrue($license['valid']);
        $this->assertContains('trace_analysis', $license['features']);
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
