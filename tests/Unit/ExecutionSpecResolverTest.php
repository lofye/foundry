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
        $this->assertSame('docs/features/blog/specs/001-initial.md', $spec->path);
        $this->assertSame('001-initial', $spec->name);
        $this->assertSame('001', $spec->id);
        $this->assertNull($spec->parentId);
        $this->assertSame(['Add initial blog scaffolding.'], $spec->requestedChanges);
    }

    public function test_unique_shorthand_resolution_is_deterministic(): void
    {
        $this->writeExecutionSpec('blog', '001-initial', 'blog');

        $spec = $this->resolver()->resolve('001-initial');

        $this->assertSame('blog/001-initial', $spec->specId);
        $this->assertSame('blog', $spec->feature);
    }

    public function test_hierarchical_execution_spec_ids_resolve_and_expose_parent_relationship(): void
    {
        $this->writeExecutionSpec('blog', '015.002.001-grandchild', 'blog');

        $spec = $this->resolver()->resolve('015.002.001-grandchild');

        $this->assertSame('blog/015.002.001-grandchild', $spec->specId);
        $this->assertSame('015.002.001-grandchild', $spec->name);
        $this->assertSame('015.002.001', $spec->id);
        $this->assertSame('015.002', $spec->parentId);
    }

    public function test_feature_and_id_shorthand_resolves_the_correct_active_execution_spec(): void
    {
        $this->writeExecutionSpec('blog', '001-initial', 'blog');

        $spec = $this->resolver()->resolveWithinFeature('blog', '001');

        $this->assertSame('blog/001-initial', $spec->specId);
        $this->assertSame('blog', $spec->feature);
        $this->assertSame('001', $spec->id);
    }

    public function test_feature_and_hierarchical_id_shorthand_resolves_correctly(): void
    {
        $this->writeExecutionSpec('blog', '015.001-nested-work', 'blog');

        $spec = $this->resolver()->resolveWithinFeature('blog', '015.001');

        $this->assertSame('blog/015.001-nested-work', $spec->specId);
        $this->assertSame('015.001', $spec->id);
        $this->assertSame('015', $spec->parentId);
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

    public function test_feature_and_id_shorthand_rejects_duplicate_active_ids_within_feature(): void
    {
        $this->writeExecutionSpec('blog', '001-first', 'blog');
        $this->writeExecutionSpec('blog', '001-second', 'blog');

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolveWithinFeature('blog', '001'));

        $this->assertSame('EXECUTION_SPEC_AMBIGUOUS', $error->errorCode);
    }

    public function test_feature_and_id_shorthand_rejects_draft_only_matches(): void
    {
        $this->writeRawDraftExecutionSpec('blog', '001-initial', <<<MD
# Execution Spec: 001-initial

## Feature

- blog

## Purpose

- Execute a bounded implementation step.

## Scope

- Add initial blog scaffolding.

## Constraints

- Keep execution deterministic.

## Requested Changes

- Add initial blog scaffolding.
MD);

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolveWithinFeature('blog', '001'));

        $this->assertSame('EXECUTION_SPEC_DRAFT_ONLY', $error->errorCode);
    }

    public function test_unique_shorthand_rejects_draft_only_matches(): void
    {
        $this->writeRawDraftExecutionSpec('blog', '001-initial', <<<MD
# Execution Spec: 001-initial

## Feature

- blog

## Purpose

- Execute a bounded implementation step.

## Scope

- Add initial blog scaffolding.

## Constraints

- Keep execution deterministic.

## Requested Changes

- Add initial blog scaffolding.
MD);

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('001-initial'));

        $this->assertSame('EXECUTION_SPEC_DRAFT_ONLY', $error->errorCode);
    }

    public function test_explicit_draft_path_rejects_with_draft_only_error(): void
    {
        $this->writeRawDraftExecutionSpec('blog', '001-initial', <<<MD
# Execution Spec: 001-initial

## Feature

- blog

## Purpose

- Execute a bounded implementation step.

## Scope

- Add initial blog scaffolding.

## Constraints

- Keep execution deterministic.

## Requested Changes

- Add initial blog scaffolding.
MD);

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('docs/features/blog/specs/drafts/001-initial.md'));

        $this->assertSame('EXECUTION_SPEC_DRAFT_ONLY', $error->errorCode);
    }

    public function test_feature_and_id_shorthand_rejects_unknown_feature(): void
    {
        $error = $this->expectFoundryError(fn() => $this->resolver()->resolveWithinFeature('unknown-feature', '001'));

        $this->assertSame('EXECUTION_SPEC_FEATURE_NOT_FOUND', $error->errorCode);
    }

    public function test_feature_and_id_shorthand_rejects_invalid_feature_name(): void
    {
        $error = $this->expectFoundryError(fn() => $this->resolver()->resolveWithinFeature('Bad Feature', '001'));

        $this->assertSame('EXECUTION_SPEC_FEATURE_INVALID', $error->errorCode);
        $this->assertSame('Bad Feature', $error->details['feature']);
    }

    public function test_feature_and_id_shorthand_rejects_malformed_id(): void
    {
        $this->writeExecutionSpec('blog', '001-initial', 'blog');

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolveWithinFeature('blog', '18'));

        $this->assertSame('EXECUTION_SPEC_ID_INVALID', $error->errorCode);
    }

    public function test_non_canonical_flat_execution_spec_path_is_rejected(): void
    {
        $path = $this->project->root . '/docs/features/blog-1/specs.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, '# Execution Spec: blog-1');

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('docs/features/blog-1.md'));

        $this->assertSame('EXECUTION_SPEC_PATH_NON_CANONICAL', $error->errorCode);
    }

    public function test_noncanonical_unique_shorthand_is_rejected_before_searching(): void
    {
        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('not-a-spec'));

        $this->assertSame('EXECUTION_SPEC_PATH_NON_CANONICAL', $error->errorCode);
    }

    public function test_unique_shorthand_with_markdown_extension_reports_not_found_deterministically(): void
    {
        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('001-missing.md'));

        $this->assertSame('EXECUTION_SPEC_NOT_FOUND', $error->errorCode);
        $this->assertSame('001-missing.md', $error->details['spec_id']);
    }

    public function test_heading_must_match_filename_only(): void
    {
        $this->writeExecutionSpec('blog', '001-initial', 'blog', '# Execution Spec: blog/001-initial');

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('blog/001-initial'));

        $this->assertSame('EXECUTION_SPEC_HEADING_NON_CANONICAL', $error->errorCode);
    }

    public function test_requested_changes_preserve_negative_parent_context_for_fragment_bullets(): void
    {
        $this->writeRawExecutionSpec('execution-spec-system', '004-spec-auto-log-on-implementation', <<<MD
# Execution Spec: 004-spec-auto-log-on-implementation

## Feature

- execution-spec-system

## Purpose

- Keep auto-log execution deterministic.

## Scope

- Hook into implement spec.

## Constraints

- Keep execution deterministic.

## Requested Changes

### 1. Trigger Point

After successful implementation of an active execution spec, Foundry must automatically append an implementation entry to:

`docs/features/implementation-log.md`

This must occur only after implementation has succeeded.

Do not append log entries:
- before implementation succeeds
- for draft specs
- for failed or partial implementations
MD);

        $spec = $this->resolver()->resolve('execution-spec-system/004-spec-auto-log-on-implementation');

        $this->assertContains('Do not append log entries before implementation succeeds.', $spec->requestedChanges);
        $this->assertContains('Do not append log entries for draft specs.', $spec->requestedChanges);
        $this->assertContains('Do not append log entries for failed or partial implementations.', $spec->requestedChanges);
        $this->assertNotContains('before implementation succeeds', $spec->requestedChanges);
        $this->assertNotContains('for draft specs', $spec->requestedChanges);
        $this->assertNotContains('for failed or partial implementations', $spec->requestedChanges);
    }

    public function test_resolve_rejects_empty_spec_id(): void
    {
        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('   '));
        $this->assertSame('EXECUTION_SPEC_ID_REQUIRED', $error->errorCode);
    }

    public function test_resolve_rejects_noncanonical_nested_path_input(): void
    {
        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('docs/features/blog/specs/extra/001-initial.md'));
        $this->assertSame('EXECUTION_SPEC_PATH_NON_CANONICAL', $error->errorCode);
    }

    public function test_resolve_requires_feature_section(): void
    {
        $this->writeRawExecutionSpec('blog', '001-initial', <<<MD
# Execution Spec: 001-initial

## Purpose

- Missing feature section.
MD);

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('blog/001-initial'));
        $this->assertSame('EXECUTION_SPEC_FEATURE_SECTION_MISSING', $error->errorCode);
    }

    public function test_resolve_treats_empty_feature_section_as_missing(): void
    {
        $this->writeRawExecutionSpec('blog', '001-initial', <<<MD
# Execution Spec: 001-initial

## Feature

## Purpose

- Empty feature sections are not executable.
MD);

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('blog/001-initial'));
        $this->assertSame('EXECUTION_SPEC_FEATURE_SECTION_MISSING', $error->errorCode);
    }

    public function test_resolve_rejects_invalid_feature_name_in_feature_section(): void
    {
        $this->writeRawExecutionSpec('blog', '001-initial', <<<MD
# Execution Spec: 001-initial

## Feature

- Blog Invalid

## Purpose

- Invalid feature value.
MD);

        $error = $this->expectFoundryError(fn() => $this->resolver()->resolve('blog/001-initial'));
        $this->assertSame('EXECUTION_SPEC_FEATURE_INVALID', $error->errorCode);
    }

    private function resolver(): ExecutionSpecResolver
    {
        return new ExecutionSpecResolver(new Paths($this->project->root));
    }

    private function writeExecutionSpec(string $feature, string $name, string $declaredFeature, ?string $heading = null): void
    {
        $heading ??= '# Execution Spec: ' . $name;

        $this->writeRawExecutionSpec($feature, $name, <<<MD
{$heading}

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

    private function writeRawExecutionSpec(string $feature, string $name, string $contents): void
    {
        $path = $this->project->root . '/docs/features/' . $feature . '/specs/' . $name . '.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function writeRawDraftExecutionSpec(string $feature, string $name, string $contents): void
    {
        $path = $this->project->root . '/docs/features/' . $feature . '/specs/drafts/' . $name . '.md';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
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
