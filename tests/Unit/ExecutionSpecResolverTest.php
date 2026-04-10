<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ExecutionSpecResolver;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ExecutionSpecResolverTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_execution_spec_resolves_correctly_and_extracts_target_feature(): void
    {
        $this->writeExecutionSpec('blog', '001-initial', 'blog');

        $spec = $this->resolver()->resolve('blog/001-initial');

        $this->assertSame('blog/001-initial', $spec->specId);
        $this->assertSame('blog', $spec->feature);
        $this->assertSame('docs/specs/blog/001-initial.md', $spec->path);
        $this->assertSame(['Add initial blog scaffolding.'], $spec->requestedChanges);
    }

    public function test_unique_shorthand_resolution_is_deterministic(): void
    {
        $this->writeExecutionSpec('blog', '001-initial', 'blog');

        $spec = $this->resolver()->resolve('001-initial');

        $this->assertSame('blog/001-initial', $spec->specId);
        $this->assertSame('blog', $spec->feature);
    }

    public function test_file_path_and_feature_section_disagreement_fails_clearly(): void
    {
        $this->writeExecutionSpec('blog', '001-initial', 'news');

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('blog/001-initial'));

        $this->assertSame('EXECUTION_SPEC_FEATURE_MISMATCH', $error->errorCode);
    }

    public function test_ambiguous_shorthand_execution_spec_fails_clearly(): void
    {
        $this->writeExecutionSpec('blog', '001-initial', 'blog');
        $this->writeExecutionSpec('news', '001-initial', 'news');

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('001-initial'));

        $this->assertSame('EXECUTION_SPEC_AMBIGUOUS', $error->errorCode);
    }

    public function test_non_canonical_flat_execution_spec_path_is_rejected(): void
    {
        $path = $this->project->root . '/docs/specs/blog-1.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, '# Execution Spec: blog-1');

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('docs/specs/blog-1.md'));

        $this->assertSame('EXECUTION_SPEC_PATH_NON_CANONICAL', $error->errorCode);
    }

    private function resolver(): ExecutionSpecResolver
    {
        return new ExecutionSpecResolver(new Paths($this->project->root));
    }

    private function writeExecutionSpec(string $feature, string $name, string $declaredFeature): void
    {
        $path = $this->project->root . '/docs/specs/' . $feature . '/' . $name . '.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, <<<MD
# Execution Spec: {$feature}/{$name}

## Feature

- {$declaredFeature}

## Purpose

- Execute a bounded implementation step.

## Scope

- Add initial blog scaffolding.

## Constraints

- Keep execution deterministic.

## Requested Changes

- Add initial blog scaffolding.
MD);
    }

    /**
     * @param \Closure():mixed $callback
     */
    private function expectFoundryError(\Closure $callback): FoundryError
    {
        try {
            $callback();
        } catch (FoundryError $error) {
            return $error;
        }

        $this->fail('Expected FoundryError to be thrown.');
    }
}
