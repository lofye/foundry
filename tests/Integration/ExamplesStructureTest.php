<?php
declare(strict_types=1);

namespace Foundry\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ExamplesStructureTest extends TestCase
{
    public function test_blog_api_example_contains_required_features(): void
    {
        $base = getcwd() . '/examples/blog-api/app/features';
        foreach (['list_posts', 'view_post', 'publish_post', 'update_post', 'delete_post'] as $feature) {
            $this->assertDirectoryExists($base . '/' . $feature);
            $this->assertFileExists($base . '/' . $feature . '/feature.yaml');
            $this->assertFileExists($base . '/' . $feature . '/context.manifest.json');
        }
    }

    public function test_dashboard_example_contains_required_features(): void
    {
        $base = getcwd() . '/examples/dashboard/app/features';
        foreach (['login', 'current_user', 'list_notifications', 'upload_avatar'] as $feature) {
            $this->assertDirectoryExists($base . '/' . $feature);
            $this->assertFileExists($base . '/' . $feature . '/feature.yaml');
        }
    }

    public function test_ai_pipeline_example_contains_required_features(): void
    {
        $base = getcwd() . '/examples/ai-pipeline/app/features';
        foreach (['submit_document', 'extract_summary', 'classify_document', 'queue_ai_summary_job', 'fetch_ai_result'] as $feature) {
            $this->assertDirectoryExists($base . '/' . $feature);
            $this->assertFileExists($base . '/' . $feature . '/feature.yaml');
        }
    }

    public function test_phase0_examples_are_documented(): void
    {
        $this->assertFileExists(getcwd() . '/examples/phase0/README.md');
        $this->assertFileExists(getcwd() . '/examples/phase0b/README.md');
        $this->assertFileExists(getcwd() . '/examples/phase0c/README.md');
        $this->assertFileExists(getcwd() . '/examples/phase0d/README.md');
        $this->assertFileExists(getcwd() . '/examples/phase1/README.md');
        $this->assertFileExists(getcwd() . '/examples/phase2/README.md');
    }
}
