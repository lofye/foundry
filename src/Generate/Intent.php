<?php

declare(strict_types=1);

namespace Foundry\Generate;

final readonly class Intent
{
    /**
     * @param array<int,string> $packHints
     */
    public function __construct(
        public string $raw,
        public string $mode,
        public ?string $target = null,
        public ?string $workflowPath = null,
        public bool $multiStep = false,
        public bool $interactive = false,
        public bool $dryRun = false,
        public bool $policyCheck = false,
        public bool $skipVerify = false,
        public bool $explainAfter = false,
        public bool $allowRisky = false,
        public bool $allowPolicyViolations = false,
        public bool $allowDirty = false,
        public bool $allowPackInstall = false,
        public bool $gitCommit = false,
        public ?string $gitCommitMessage = null,
        public array $packHints = [],
    ) {}

    /**
     * @return array<int,string>
     */
    public static function supportedModes(): array
    {
        return ['new', 'modify', 'repair'];
    }

    /**
     * @return array<int,string>
     */
    public function tokens(): array
    {
        $normalized = strtolower($this->raw);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        $parts = array_values(array_filter(array_map('trim', explode(' ', $normalized))));

        return array_values(array_unique(array_map('strval', $parts)));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'mode' => $this->mode,
            'target' => $this->target,
            'workflow_path' => $this->workflowPath,
            'multi_step' => $this->multiStep,
            'interactive' => $this->interactive,
            'dry_run' => $this->dryRun,
            'policy_check' => $this->policyCheck,
            'skip_verify' => $this->skipVerify,
            'explain' => $this->explainAfter,
            'allow_risky' => $this->allowRisky,
            'allow_policy_violations' => $this->allowPolicyViolations,
            'allow_dirty' => $this->allowDirty,
            'allow_pack_install' => $this->allowPackInstall,
            'git_commit' => $this->gitCommit,
            'git_commit_message' => $this->gitCommitMessage,
            'packs' => $this->packHints,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $packs = array_values(array_filter(array_map(
            static fn(mixed $pack): string => trim((string) $pack),
            (array) ($data['packs'] ?? []),
        ), static fn(string $pack): bool => $pack !== ''));
        $packs = array_values(array_unique($packs));
        sort($packs);

        return new self(
            raw: (string) ($data['raw'] ?? ''),
            mode: (string) ($data['mode'] ?? 'new'),
            target: is_string($data['target'] ?? null) && trim((string) $data['target']) !== ''
                ? trim((string) $data['target'])
                : null,
            workflowPath: is_string($data['workflow_path'] ?? null) && trim((string) ($data['workflow_path'] ?? null)) !== ''
                ? trim((string) $data['workflow_path'])
                : null,
            multiStep: ($data['multi_step'] ?? false) === true,
            interactive: ($data['interactive'] ?? false) === true,
            dryRun: ($data['dry_run'] ?? false) === true,
            policyCheck: ($data['policy_check'] ?? false) === true,
            skipVerify: ($data['skip_verify'] ?? false) === true,
            explainAfter: ($data['explain'] ?? false) === true,
            allowRisky: ($data['allow_risky'] ?? false) === true,
            allowPolicyViolations: ($data['allow_policy_violations'] ?? false) === true,
            allowDirty: ($data['allow_dirty'] ?? false) === true,
            allowPackInstall: ($data['allow_pack_install'] ?? false) === true,
            gitCommit: ($data['git_commit'] ?? false) === true,
            gitCommitMessage: is_string($data['git_commit_message'] ?? null) && trim((string) $data['git_commit_message']) !== ''
                ? trim((string) $data['git_commit_message'])
                : null,
            packHints: $packs,
        );
    }

    public function withAllowRisky(bool $allowRisky): self
    {
        return new self(
            raw: $this->raw,
            mode: $this->mode,
            target: $this->target,
            workflowPath: $this->workflowPath,
            multiStep: $this->multiStep,
            interactive: $this->interactive,
            dryRun: $this->dryRun,
            policyCheck: $this->policyCheck,
            skipVerify: $this->skipVerify,
            explainAfter: $this->explainAfter,
            allowRisky: $allowRisky,
            allowPolicyViolations: $this->allowPolicyViolations,
            allowDirty: $this->allowDirty,
            allowPackInstall: $this->allowPackInstall,
            gitCommit: $this->gitCommit,
            gitCommitMessage: $this->gitCommitMessage,
            packHints: $this->packHints,
        );
    }

    public function withAllowPolicyViolations(bool $allowPolicyViolations): self
    {
        return new self(
            raw: $this->raw,
            mode: $this->mode,
            target: $this->target,
            workflowPath: $this->workflowPath,
            multiStep: $this->multiStep,
            interactive: $this->interactive,
            dryRun: $this->dryRun,
            policyCheck: $this->policyCheck,
            skipVerify: $this->skipVerify,
            explainAfter: $this->explainAfter,
            allowRisky: $this->allowRisky,
            allowPolicyViolations: $allowPolicyViolations,
            allowDirty: $this->allowDirty,
            allowPackInstall: $this->allowPackInstall,
            gitCommit: $this->gitCommit,
            gitCommitMessage: $this->gitCommitMessage,
            packHints: $this->packHints,
        );
    }

    public function isWorkflow(): bool
    {
        return $this->workflowPath !== null && $this->workflowPath !== '';
    }
}
