<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Upgrade\DeprecationMetadata;
use Foundry\Upgrade\UpgradeIssue;
use Foundry\Upgrade\UpgradeReport;
use Foundry\Upgrade\VersionComparator;
use PHPUnit\Framework\TestCase;

final class UpgradeSupportTypesTest extends TestCase
{
    public function test_version_comparator_handles_stable_dev_main_and_invalid_versions(): void
    {
        $this->assertTrue(VersionComparator::isValid('1.2.3'));
        $this->assertTrue(VersionComparator::isValid('dev-main'));
        $this->assertFalse(VersionComparator::isValid('1.2.x'));

        $this->assertSame(0, VersionComparator::compare('1.2.3', '1.2.3'));
        $this->assertSame(1, VersionComparator::compare('dev-main', '1.2.3'));
        $this->assertSame(-1, VersionComparator::compare('1.2.3', 'dev-main'));
        $this->assertGreaterThan(0, VersionComparator::compare('2.0.0', '1.9.9'));
        $this->assertLessThan(0, VersionComparator::compare('invalid-left', 'invalid-right'));
    }

    public function test_deprecation_metadata_reports_applicability_and_array_shape(): void
    {
        $metadata = new DeprecationMetadata(
            id: 'feature_manifest.v1',
            title: 'Feature manifest v1',
            severity: 'warning',
            category: 'migrations',
            introducedIn: '0.4.0',
            removalVersion: '1.0.0',
            whyItMatters: 'Old manifests will stop loading.',
            migration: 'Upgrade the manifest before 1.0.',
            reference: 'docs/upgrade-safety.md#feature-manifest-v1',
        );

        $this->assertFalse($metadata->appliesTo('0.4.0'));
        $this->assertTrue($metadata->appliesTo('1.0.0'));
        $this->assertSame([
            'id' => 'feature_manifest.v1',
            'title' => 'Feature manifest v1',
            'severity' => 'warning',
            'category' => 'migrations',
            'introduced_in' => '0.4.0',
            'removal_version' => '1.0.0',
            'why_it_matters' => 'Old manifests will stop loading.',
            'migration' => 'Upgrade the manifest before 1.0.',
            'reference' => 'docs/upgrade-safety.md#feature-manifest-v1',
        ], $metadata->toArray());
    }

    public function test_upgrade_report_renders_warning_headline_and_skips_empty_affected_values(): void
    {
        $report = new UpgradeReport(
            ok: true,
            currentVersion: '0.4.0',
            targetVersion: '1.0.0',
            graphVersion: 1,
            commandPrefix: 'php bin/foundry',
            summary: ['error' => 0, 'warning' => 1, 'info' => 0, 'total' => 1],
            issues: [
                new UpgradeIssue(
                    code: 'FDY1301_DEPRECATED_CLI_USAGE',
                    severity: 'warning',
                    category: 'upgrade',
                    summary: 'Legacy command detected.',
                    affected: ['source_path' => 'README.md', 'line' => '', 'match' => null],
                    whyItMatters: 'Old commands will be removed.',
                    introducedIn: '0.4.0',
                    targetVersion: '1.0.0',
                    migration: 'Use `new` instead of `init app`.',
                    reference: 'docs/upgrade-safety.md',
                ),
            ],
            checks: [],
        );

        $rendered = $report->renderHuman();

        $this->assertStringContainsString('Upgrade check found issues to review.', $rendered);
        $this->assertStringContainsString('Affected: source_path=README.md', $rendered);
        $this->assertStringNotContainsString('line=', $rendered);
        $this->assertStringNotContainsString('match=', $rendered);
    }
}
