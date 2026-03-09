<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Compiler\Migration\SpecFormat;
use Foundry\Compiler\Projection\ProjectionEmitter;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;

final class ExtensionRegistry
{
    /**
     * @var array<string,CompilerExtension>
     */
    private array $extensions = [];

    /**
     * @var array<int,string>
     */
    private array $registrationSources = [];

    /**
     * @param array<int,CompilerExtension> $extensions
     */
    public function __construct(array $extensions = [])
    {
        foreach ($extensions as $extension) {
            $this->register($extension);
        }
    }

    public static function forPaths(Paths $paths): self
    {
        $registry = new self([
            new CoreCompilerExtension(),
        ]);

        $loader = new ExtensionRegistrationLoader();
        $loaded = $loader->load($paths);

        foreach ((array) ($loaded['source_paths'] ?? []) as $source) {
            $sourcePath = (string) $source;
            if ($sourcePath === '') {
                continue;
            }
            $registry->registrationSources[] = $sourcePath;
        }

        foreach ((array) ($loaded['classes'] ?? []) as $class) {
            $className = (string) $class;
            if ($className === '') {
                continue;
            }

            if (!class_exists($className)) {
                throw new FoundryError(
                    'FDY7011_EXTENSION_CLASS_NOT_FOUND',
                    'extensions',
                    ['extension_class' => $className],
                    'Registered extension class not found.',
                );
            }

            if (!is_subclass_of($className, CompilerExtension::class)) {
                throw new FoundryError(
                    'FDY7012_EXTENSION_CLASS_INVALID',
                    'extensions',
                    ['extension_class' => $className],
                    'Registered extension class must implement CompilerExtension.',
                );
            }

            /** @var CompilerExtension $extension */
            $extension = new $className();
            $registry->register($extension);
        }

        sort($registry->registrationSources);

        return $registry;
    }

    public function register(CompilerExtension $extension): void
    {
        $key = $extension->name() . '@' . $extension->version();
        $name = $extension->name();

        foreach ($this->extensions as $existing) {
            if ($existing->name() === $name && $existing->version() !== $extension->version()) {
                throw new FoundryError(
                    'FDY7005_DUPLICATE_EXTENSION_ID',
                    'extensions',
                    ['extension' => $name],
                    'Duplicate extension name detected.',
                );
            }
        }

        $this->extensions[$key] = $extension;
        ksort($this->extensions);
    }

    /**
     * @return array<int,CompilerExtension>
     */
    public function all(): array
    {
        return array_values($this->extensions);
    }

    /**
     * @return array<int,string>
     */
    public function registrationSources(): array
    {
        return $this->registrationSources;
    }

    public function extension(string $name): ?CompilerExtension
    {
        foreach ($this->all() as $extension) {
            if ($extension->name() === $name) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * @return array<int,ProjectionEmitter>
     */
    public function projectionEmitters(): array
    {
        $emitters = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->projectionEmitters() as $emitter) {
                $emitters[] = $emitter;
            }
        }

        usort($emitters, static fn (ProjectionEmitter $a, ProjectionEmitter $b): int => strcmp($a->id(), $b->id()));

        return $emitters;
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function packs(): array
    {
        $packs = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->packs() as $pack) {
                $packs[] = $pack;
            }
        }

        usort($packs, static fn (PackDefinition $a, PackDefinition $b): int => strcmp($a->name, $b->name));

        return $packs;
    }

    public function packRegistry(): PackRegistry
    {
        return new PackRegistry($this->packs());
    }

    /**
     * @return array<int,MigrationRule>
     */
    public function migrationRules(): array
    {
        $rules = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->migrationRules() as $rule) {
                $rules[] = $rule;
            }
        }

        usort($rules, static fn (MigrationRule $a, MigrationRule $b): int => strcmp($a->id(), $b->id()));

        return $rules;
    }

    /**
     * @return array<int,SpecFormat>
     */
    public function specFormats(): array
    {
        $formats = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->specFormats() as $format) {
                $formats[] = $format;
            }
        }

        usort($formats, static fn (SpecFormat $a, SpecFormat $b): int => strcmp($a->name, $b->name));

        return $formats;
    }

    /**
     * @return array<int,Codemod>
     */
    public function codemods(): array
    {
        $codemods = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->codemods() as $codemod) {
                $codemods[] = $codemod;
            }
        }

        usort($codemods, static fn (Codemod $a, Codemod $b): int => strcmp($a->id(), $b->id()));

        return $codemods;
    }

    public function compatibilityReport(string $frameworkVersion, int $graphVersion): CompatibilityReport
    {
        $checker = new CompatibilityChecker($this, $this->packRegistry());

        return $checker->check($frameworkVersion, $graphVersion);
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function passesForPhase(string $phase): array
    {
        $rows = [];
        foreach ($this->all() as $extension) {
            $passes = match ($phase) {
                'discovery' => $extension->discoveryPasses(),
                'normalize' => $extension->normalizePasses(),
                'link' => $extension->linkPasses(),
                'validate' => $extension->validatePasses(),
                'enrich' => $extension->enrichPasses(),
                'analyze' => $extension->analyzePasses(),
                'emit' => $extension->emitPasses(),
                default => [],
            };

            foreach ($passes as $pass) {
                $rows[] = [
                    'extension' => $extension->name(),
                    'priority' => $extension->passPriority($phase, $pass),
                    'pass' => $pass,
                ];
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ((int) ($a['priority'] ?? 0) <=> (int) ($b['priority'] ?? 0))
                ?: strcmp((string) ($a['extension'] ?? ''), (string) ($b['extension'] ?? ''))
                ?: strcmp(get_class($a['pass']), get_class($b['pass'])),
        );

        return array_values(array_map(
            static fn (array $row): CompilerPass => $row['pass'],
            $rows,
        ));
    }

    /**
     * @param callable(CompilerExtension):array<int,mixed> $selector
     * @return array<int,mixed>
     */
    public function collect(callable $selector): array
    {
        $items = [];
        foreach ($this->all() as $extension) {
            foreach ($selector($extension) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function inspectRows(): array
    {
        $rows = [];
        foreach ($this->all() as $extension) {
            $row = $extension->describe();
            $row['descriptor'] = $extension->descriptor()->toArray();
            $rows[] = $row;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')),
        );

        return $rows;
    }
}
