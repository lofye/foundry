<?php

declare(strict_types=1);

namespace Foundry\Packs;

use Foundry\Compiler\Extensions\CompilerExtension;
use Foundry\Support\FoundryError;

final class PackContext
{
    private ?CompilerExtension $extension = null;

    /**
     * @var array<string,array<int,string>>
     */
    private array $contributions = [
        'commands' => [],
        'schemas' => [],
        'workflows' => [],
        'events' => [],
        'guards' => [],
        'generators' => [],
        'docs_metadata' => [],
    ];

    public function __construct(
        private readonly PackManifest $manifest,
        private readonly string $installPath,
    ) {}

    public function manifest(): PackManifest
    {
        return $this->manifest;
    }

    public function installPath(): string
    {
        return $this->installPath;
    }

    public function registerExtension(CompilerExtension $extension): void
    {
        if ($this->extension !== null) {
            throw new FoundryError(
                'PACK_EXTENSION_ALREADY_REGISTERED',
                'validation',
                ['pack' => $this->manifest->name],
                'A pack may register only one compiler extension entrypoint.',
            );
        }

        $this->extension = $extension;
    }

    public function registerCommand(string $signature): void
    {
        $this->registerContribution('commands', $signature);
    }

    public function registerSchema(string $name): void
    {
        $this->registerContribution('schemas', $name);
    }

    public function registerWorkflow(string $name): void
    {
        $this->registerContribution('workflows', $name);
    }

    public function registerEvent(string $name): void
    {
        $this->registerContribution('events', $name);
    }

    public function registerGuard(string $name): void
    {
        $this->registerContribution('guards', $name);
    }

    public function registerGenerator(string $name): void
    {
        $this->registerContribution('generators', $name);
    }

    public function registerDocsMetadata(string $name): void
    {
        $this->registerContribution('docs_metadata', $name);
    }

    public function extension(): ?CompilerExtension
    {
        return $this->extension;
    }

    /**
     * @return array<string,array<int,string>>
     */
    public function contributions(): array
    {
        $normalized = $this->contributions;
        foreach ($normalized as &$values) {
            $values = array_values(array_unique(array_map('strval', $values)));
            sort($values);
        }
        unset($values);

        ksort($normalized);

        return $normalized;
    }

    private function registerContribution(string $type, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $this->contributions[$type] ??= [];
        $this->contributions[$type][] = $value;
    }
}
