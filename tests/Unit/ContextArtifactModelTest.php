<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Context\ContextFileResolver;
use Foundry\Context\DecisionLedgerValidator;
use Foundry\Context\FeatureNameValidator;
use Foundry\Context\SpecValidator;
use Foundry\Context\StateValidator;
use Foundry\Context\Validation\ValidationResult;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class ContextArtifactModelTest extends TestCase
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

    public function test_feature_name_validator_accepts_valid_kebab_case_names(): void
    {
        $result = (new FeatureNameValidator())->validate('blog-posts-2');

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->issues);
    }

    public function test_feature_name_validator_rejects_uppercase_names(): void
    {
        $result = (new FeatureNameValidator())->validate('Blog');

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_FEATURE_NAME_UPPERCASE', $this->issueCodes($result));
    }

    public function test_feature_name_validator_rejects_underscores(): void
    {
        $result = (new FeatureNameValidator())->validate('blog_posts');

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_FEATURE_NAME_UNDERSCORE', $this->issueCodes($result));
    }

    public function test_feature_name_validator_rejects_spaces(): void
    {
        $result = (new FeatureNameValidator())->validate('blog posts');

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_FEATURE_NAME_WHITESPACE', $this->issueCodes($result));
    }

    public function test_feature_name_validator_rejects_invalid_characters(): void
    {
        $result = (new FeatureNameValidator())->validate('blog!posts');

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_FEATURE_NAME_INVALID_CHARACTER', $this->issueCodes($result));
    }

    public function test_feature_name_validator_rejects_leading_and_trailing_dashes(): void
    {
        $leading = (new FeatureNameValidator())->validate('-blog-posts');
        $trailing = (new FeatureNameValidator())->validate('blog-posts-');

        $this->assertFalse($leading->valid);
        $this->assertContains('CONTEXT_FEATURE_NAME_LEADING_DASH', $this->issueCodes($leading));
        $this->assertFalse($trailing->valid);
        $this->assertContains('CONTEXT_FEATURE_NAME_TRAILING_DASH', $this->issueCodes($trailing));
    }

    public function test_feature_name_validator_rejects_repeated_dashes(): void
    {
        $result = (new FeatureNameValidator())->validate('blog--posts');

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_FEATURE_NAME_REPEATED_DASH', $this->issueCodes($result));
    }

    public function test_context_file_resolver_returns_canonical_deterministic_paths(): void
    {
        $resolver = new ContextFileResolver();
        $featureName = 'blog-posts';
        $original = $featureName;

        $paths = $resolver->paths($featureName);

        $this->assertSame('docs/features/blog-posts.spec.md', $paths['spec']);
        $this->assertSame('docs/features/blog-posts.md', $paths['state']);
        $this->assertSame('docs/features/blog-posts.decisions.md', $paths['decisions']);
        $this->assertSame($paths, $resolver->paths($featureName));
        $this->assertSame($original, $featureName);
    }

    public function test_context_file_resolver_normalizes_underscore_input_to_canonical_paths(): void
    {
        $paths = (new ContextFileResolver())->paths('blog_posts');

        $this->assertSame('docs/features/blog-posts.spec.md', $paths['spec']);
        $this->assertSame('docs/features/blog-posts.md', $paths['state']);
        $this->assertSame('docs/features/blog-posts.decisions.md', $paths['decisions']);
    }

    public function test_spec_validator_accepts_valid_minimal_spec(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->specPath($featureName));
        $this->writeFile($path, $this->minimalSpec($featureName));

        $result = (new SpecValidator())->validate($featureName, $path);

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->missing_sections);
    }

    public function test_spec_validator_accepts_optional_spec_version_section(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->specPath($featureName));
        $this->writeFile($path, str_replace(
            "## Purpose\n",
            "## Spec Version\n\n1\n\n## Purpose\n",
            $this->minimalSpec($featureName),
        ));

        $result = (new SpecValidator())->validate($featureName, $path);

        $this->assertTrue($result->valid);
    }

    public function test_spec_validator_detects_missing_file(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->specPath($featureName));

        $result = (new SpecValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertFalse($result->file_exists);
        $this->assertContains('CONTEXT_FILE_MISSING', $this->issueCodes($result));
    }

    public function test_spec_validator_detects_missing_section(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->specPath($featureName));
        $this->writeFile($path, str_replace("## Goals\n\n- TBD.\n\n", '', $this->minimalSpec($featureName)));

        $result = (new SpecValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertContains('Goals', $result->missing_sections);
        $this->assertContains('CONTEXT_SPEC_SECTION_MISSING', $this->issueCodes($result));
    }

    public function test_spec_validator_detects_malformed_heading(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->specPath($featureName));
        $this->writeFile($path, str_replace('# Feature Spec: blog-posts', '# Spec: blog-posts', $this->minimalSpec($featureName)));

        $result = (new SpecValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_SPEC_HEADING_INVALID', $this->issueCodes($result));
    }

    public function test_spec_validator_rejects_noncanonical_spec_filename_pattern(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath('docs/features/blog-posts.spec.v2.md');
        $this->writeFile($path, $this->minimalSpec($featureName));

        $result = (new SpecValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_SPEC_PATH_NON_CANONICAL', $this->issueCodes($result));
    }

    public function test_state_validator_accepts_valid_minimal_state_document(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->statePath($featureName));
        $this->writeFile($path, $this->minimalState($featureName));

        $result = (new StateValidator())->validate($featureName, $path);

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->missing_sections);
    }

    public function test_state_validator_detects_missing_section(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->statePath($featureName));
        $this->writeFile($path, str_replace("## Open Questions\n\n- TBD.\n\n", '', $this->minimalState($featureName)));

        $result = (new StateValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertContains('Open Questions', $result->missing_sections);
        $this->assertContains('CONTEXT_STATE_SECTION_MISSING', $this->issueCodes($result));
    }

    public function test_state_validator_detects_malformed_heading(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->statePath($featureName));
        $this->writeFile($path, str_replace('# Feature: blog-posts', '# State: blog-posts', $this->minimalState($featureName)));

        $result = (new StateValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_STATE_HEADING_INVALID', $this->issueCodes($result));
    }

    public function test_decision_ledger_validator_detects_missing_file(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->decisionsPath($featureName));

        $result = (new DecisionLedgerValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertFalse($result->file_exists);
        $this->assertContains('CONTEXT_FILE_MISSING', $this->issueCodes($result));
    }

    public function test_decision_ledger_validator_accepts_valid_ledger(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->decisionsPath($featureName));
        $this->writeFile($path, $this->minimalDecisionLedger());

        $result = (new DecisionLedgerValidator())->validate($featureName, $path);

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->missing_sections);
    }

    public function test_decision_ledger_validator_detects_malformed_entry(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->decisionsPath($featureName));
        $this->writeFile($path, "### Note: Use canonical files\n");

        $result = (new DecisionLedgerValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertContains('CONTEXT_DECISION_ENTRY_MALFORMED', $this->issueCodes($result));
    }

    public function test_decision_ledger_validator_detects_missing_subsection(): void
    {
        $featureName = 'blog-posts';
        $path = $this->contextPath((new ContextFileResolver())->decisionsPath($featureName));
        $this->writeFile($path, str_replace("**Impact**\n\nTBD.\n\n", '', $this->minimalDecisionLedger()));

        $result = (new DecisionLedgerValidator())->validate($featureName, $path);

        $this->assertFalse($result->valid);
        $this->assertContains('Impact', $result->missing_sections);
        $this->assertContains('CONTEXT_DECISION_SUBSECTION_MISSING', $this->issueCodes($result));
    }

    private function contextPath(string $relativePath): string
    {
        return $this->project->root . '/' . $relativePath;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @return array<int,string>
     */
    private function issueCodes(ValidationResult $result): array
    {
        return array_values(array_map(
            static fn($issue): string => $issue->code,
            $result->issues,
        ));
    }

    private function minimalSpec(string $featureName): string
    {
        return <<<MD
# Feature Spec: {$featureName}

## Purpose

TBD.

## Goals

- TBD.

## Non-Goals

- TBD.

## Constraints

- TBD.

## Expected Behavior

TBD.

## Acceptance Criteria

- TBD.

## Assumptions

- TBD.
MD;
    }

    private function minimalState(string $featureName): string
    {
        return <<<MD
# Feature: {$featureName}

## Purpose

TBD.

## Current State

TBD.

## Open Questions

- TBD.

## Next Steps

- TBD.
MD;
    }

    private function minimalDecisionLedger(): string
    {
        return <<<'MD'
### Decision: Use canonical context files

Timestamp: 2026-04-07T00:00:00+00:00

**Context**

TBD.

**Decision**

TBD.

**Reasoning**

TBD.

**Alternatives Considered**

- TBD.

**Impact**

TBD.

**Spec Reference**

TBD.
MD;
    }
}
