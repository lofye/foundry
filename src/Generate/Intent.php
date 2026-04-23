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
        public bool $interactive = false,
        public bool $dryRun = false,
        public bool $skipVerify = false,
        public bool $explainAfter = false,
        public bool $allowRisky = false,
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
            'interactive' => $this->interactive,
            'dry_run' => $this->dryRun,
            'skip_verify' => $this->skipVerify,
            'explain' => $this->explainAfter,
            'allow_risky' => $this->allowRisky,
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
            interactive: ($data['interactive'] ?? false) === true,
            dryRun: ($data['dry_run'] ?? false) === true,
            skipVerify: ($data['skip_verify'] ?? false) === true,
            explainAfter: ($data['explain'] ?? false) === true,
            allowRisky: ($data['allow_risky'] ?? false) === true,
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
            interactive: $this->interactive,
            dryRun: $this->dryRun,
            skipVerify: $this->skipVerify,
            explainAfter: $this->explainAfter,
            allowRisky: $allowRisky,
            allowDirty: $this->allowDirty,
            allowPackInstall: $this->allowPackInstall,
            gitCommit: $this->gitCommit,
            gitCommitMessage: $this->gitCommitMessage,
            packHints: $this->packHints,
        );
    }
}
