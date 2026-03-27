<?php
declare(strict_types=1);

namespace Foundry\Compiler\Extensions;

use Foundry\Compiler\Analysis\GraphAnalyzer;
use Foundry\Compiler\Codemod\Codemod;
use Foundry\Compiler\CompilerPass;
use Foundry\Compiler\Migration\DefinitionFormat;
use Foundry\Compiler\Migration\MigrationRule;
use Foundry\Doctor\DoctorCheck;
use Foundry\Compiler\Projection\ProjectionEmitter;
use Foundry\Pipeline\PipelineStageDefinition;
use Foundry\Pipeline\StageInterceptor;
use Foundry\Support\Paths;

final class ExtensionRegistry
{
    /**
     * @var array<int,array{
     *   class:string,
     *   source_path:string,
     *   extension:?CompilerExtension,
     *   name:string,
     *   version:string,
     *   descriptor:?ExtensionDescriptor,
     *   base_diagnostics:array<int,array<string,mixed>>,
     *   diagnostics:array<int,array<string,mixed>>,
     *   enabled:bool,
     *   load_order:?int,
     *   lifecycle:array<string,mixed>
     * }>
     */
    private array $entries = [];

    /**
     * @var array<int,string>
     */
    private array $registrationSources = [];

    /**
     * @var array<int,array<string,mixed>>
     */
    private array $registrationDiagnostics = [];

    private readonly ExtensionMetadataValidator $validator;

    /**
     * @param array<int,CompilerExtension> $extensions
     */
    public function __construct(array $extensions = [])
    {
        $this->validator = new ExtensionMetadataValidator();

        foreach ($extensions as $extension) {
            $this->addLoadedExtension($extension, get_class($extension), 'manual');
        }

        $this->finalize();
    }

    public static function forPaths(Paths $paths): self
    {
        $registry = new self();

        $registry->addLoadedExtension(new CoreCompilerExtension(), CoreCompilerExtension::class, 'built-in');
        $registry->addLoadedExtension(new FoundationCompilerExtension(), FoundationCompilerExtension::class, 'built-in');
        $registry->addLoadedExtension(new IntegrationCompilerExtension(), IntegrationCompilerExtension::class, 'built-in');
        $registry->addLoadedExtension(new PlatformCompilerExtension(), PlatformCompilerExtension::class, 'built-in');

        $loader = new ExtensionRegistrationLoader();
        $loaded = $loader->load($paths);

        $registry->registrationSources = array_values(array_map(
            'strval',
            (array) ($loaded['source_paths'] ?? []),
        ));
        sort($registry->registrationSources);

        foreach ((array) ($loaded['diagnostics'] ?? []) as $diagnostic) {
            if (is_array($diagnostic)) {
                $registry->registrationDiagnostics[] = $diagnostic;
            }
        }

        foreach ((array) ($loaded['entries'] ?? []) as $row) {
            $className = trim((string) ($row['class'] ?? ''));
            $sourcePath = trim((string) ($row['source_path'] ?? ''));

            if ($className === '') {
                continue;
            }

            if (!class_exists($className)) {
                $registry->registrationDiagnostics[] = $registry->registrationDiagnostic(
                    code: 'FDY7011_EXTENSION_CLASS_NOT_FOUND',
                    message: sprintf('Registered extension class %s was not found.', $className),
                    sourcePath: $sourcePath,
                    details: ['extension_class' => $className],
                );
                continue;
            }

            if (!is_subclass_of($className, CompilerExtension::class)) {
                $registry->registrationDiagnostics[] = $registry->registrationDiagnostic(
                    code: 'FDY7012_EXTENSION_CLASS_INVALID',
                    message: sprintf('Registered extension class %s must implement %s.', $className, CompilerExtension::class),
                    sourcePath: $sourcePath,
                    details: ['extension_class' => $className],
                );
                continue;
            }

            try {
                /** @var CompilerExtension $extension */
                $extension = new $className();
            } catch (\Throwable $error) {
                $registry->registrationDiagnostics[] = $registry->registrationDiagnostic(
                    code: 'FDY7013_EXTENSION_INSTANTIATION_FAILED',
                    message: sprintf('Registered extension class %s could not be instantiated: %s', $className, $error->getMessage()),
                    sourcePath: $sourcePath,
                    details: [
                        'extension_class' => $className,
                        'exception' => $error::class,
                    ],
                );
                continue;
            }

            $registry->addLoadedExtension($extension, $className, $sourcePath !== '' ? $sourcePath : 'foundry.extensions.php');
        }

        $registry->finalize();

        return $registry;
    }

    public function register(CompilerExtension $extension): void
    {
        $this->addLoadedExtension($extension, get_class($extension), 'manual');
        $this->finalize();
    }

    /**
     * @return array<int,CompilerExtension>
     */
    public function all(): array
    {
        $extensions = [];
        foreach ($this->enabledEntries() as $entry) {
            if ($entry['extension'] instanceof CompilerExtension) {
                $extensions[] = $entry['extension'];
            }
        }

        return $extensions;
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
        foreach ($this->entries as $entry) {
            if ((string) ($entry['name'] ?? '') !== $name) {
                continue;
            }

            return $entry['extension'] instanceof CompilerExtension ? $entry['extension'] : null;
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

        usort($packs, $this->comparePacks(...));

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
     * @return array<int,DefinitionFormat>
     */
    public function definitionFormats(): array
    {
        $formats = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->definitionFormats() as $format) {
                $formats[] = $format;
            }
        }

        usort($formats, static fn (DefinitionFormat $a, DefinitionFormat $b): int => strcmp($a->name, $b->name));

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

    /**
     * @return array<int,GraphAnalyzer>
     */
    public function graphAnalyzers(): array
    {
        $analyzers = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->graphAnalyzers() as $analyzer) {
                $analyzers[] = $analyzer;
            }
        }

        usort($analyzers, static fn (GraphAnalyzer $a, GraphAnalyzer $b): int => strcmp($a->id(), $b->id()));

        return $analyzers;
    }

    /**
     * @return array<int,DoctorCheck>
     */
    public function doctorChecks(): array
    {
        $checks = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->doctorChecks() as $check) {
                $checks[] = $check;
            }
        }

        usort($checks, static fn (DoctorCheck $a, DoctorCheck $b): int => strcmp($a->id(), $b->id()));

        return $checks;
    }

    /**
     * @return array<int,PipelineStageDefinition>
     */
    public function pipelineStages(): array
    {
        $stages = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->pipelineStages() as $stage) {
                $stages[] = $stage;
            }
        }

        usort(
            $stages,
            static fn (PipelineStageDefinition $a, PipelineStageDefinition $b): int => ($a->priority <=> $b->priority)
                ?: strcmp($a->name, $b->name),
        );

        return $stages;
    }

    /**
     * @return array<int,StageInterceptor>
     */
    public function pipelineInterceptors(): array
    {
        $interceptors = [];
        foreach ($this->all() as $extension) {
            foreach ($extension->pipelineInterceptors() as $interceptor) {
                $interceptors[] = $interceptor;
            }
        }

        usort(
            $interceptors,
            static fn (StageInterceptor $a, StageInterceptor $b): int => strcmp($a->stage(), $b->stage())
                ?: ($a->priority() <=> $b->priority())
                ?: strcmp($a->id(), $b->id()),
        );

        return $interceptors;
    }

    public function compatibilityReport(string $frameworkVersion, int $graphVersion): CompatibilityReport
    {
        $checker = new CompatibilityChecker($this, $this->packRegistry());

        return $checker->check($frameworkVersion, $graphVersion);
    }

    /**
     * @return array<int,CompilerPass>
     */
    public function passesForStage(string $stage): array
    {
        $rows = [];
        foreach ($this->enabledEntries() as $entry) {
            $extension = $entry['extension'];
            if (!$extension instanceof CompilerExtension) {
                continue;
            }

            $loadOrder = (int) ($entry['load_order'] ?? PHP_INT_MAX);
            foreach ($this->stagePasses($extension, $stage) as $pass) {
                $rows[] = [
                    'extension' => $extension->name(),
                    'priority' => $extension->passPriority($stage, $pass),
                    'load_order' => $loadOrder,
                    'pass' => $pass,
                ];
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ((int) ($a['priority'] ?? 0) <=> (int) ($b['priority'] ?? 0))
                ?: ((int) ($a['load_order'] ?? PHP_INT_MAX) <=> (int) ($b['load_order'] ?? PHP_INT_MAX))
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
        foreach ($this->entries as $entry) {
            $row = $entry['extension'] instanceof CompilerExtension
                ? $entry['extension']->describe()
                : [
                    'name' => $entry['name'] !== '' ? $entry['name'] : $entry['class'],
                    'version' => $entry['version'],
                    'description' => '',
                    'packs' => [],
                    'provides' => [],
                ];

            $row['class'] = $entry['class'];
            $row['source_path'] = $entry['source_path'];
            $row['descriptor'] = $entry['descriptor']?->toArray();
            $row['diagnostics'] = $entry['diagnostics'];
            $row['lifecycle'] = $entry['lifecycle'];
            $row['enabled'] = $entry['enabled'];
            $row['load_order'] = $entry['load_order'];

            $rows[] = $row;
        }

        usort($rows, [$this, 'compareInspectRows']);

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function diagnostics(): array
    {
        $diagnostics = $this->registrationDiagnostics;
        foreach ($this->entries as $entry) {
            foreach ((array) ($entry['diagnostics'] ?? []) as $diagnostic) {
                if (is_array($diagnostic)) {
                    $diagnostics[] = $diagnostic;
                }
            }
        }

        return $this->sortDiagnostics($diagnostics);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function lifecycleRows(): array
    {
        $rows = [];
        foreach ($this->entries as $entry) {
            $rows[] = [
                'name' => $entry['name'] !== '' ? $entry['name'] : $entry['class'],
                'version' => $entry['version'],
                'class' => $entry['class'],
                'source_path' => $entry['source_path'],
                'enabled' => $entry['enabled'],
                'load_order' => $entry['load_order'],
                'lifecycle' => $entry['lifecycle'],
            ];
        }

        usort($rows, [$this, 'compareInspectRows']);

        return $rows;
    }

    /**
     * @return array<int,string>
     */
    public function loadOrder(): array
    {
        $rows = $this->enabledEntries();

        return array_values(array_map(
            static fn (array $entry): string => (string) $entry['name'],
            $rows,
        ));
    }

    /**
     * @return array<int,CompilerExtension>
     */
    public function loadedExtensions(): array
    {
        $extensions = [];
        foreach ($this->entries as $entry) {
            if ($entry['extension'] instanceof CompilerExtension) {
                $extensions[] = $entry['extension'];
            }
        }

        usort($extensions, $this->compareExtensions(...));

        return $extensions;
    }

    /**
     * @return array<int,PackDefinition>
     */
    public function loadedPacks(): array
    {
        $packs = [];
        foreach ($this->loadedExtensions() as $extension) {
            foreach ($extension->packs() as $pack) {
                $packs[] = $pack;
            }
        }

        usort($packs, $this->comparePacks(...));

        return $packs;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function inspectRow(string $name): ?array
    {
        foreach ($this->inspectRows() as $row) {
            if ((string) ($row['name'] ?? '') === $name) {
                return $row;
            }
        }

        return null;
    }

    private function addLoadedExtension(CompilerExtension $extension, string $class, string $sourcePath): void
    {
        $this->entries[] = [
            'class' => $class,
            'source_path' => $sourcePath,
            'extension' => $extension,
            'name' => $extension->name(),
            'version' => $extension->version(),
            'descriptor' => null,
            'base_diagnostics' => [],
            'diagnostics' => [],
            'enabled' => false,
            'load_order' => null,
            'lifecycle' => $this->defaultLifecycle('discovered', 'pending'),
        ];
    }

    private function finalize(): void
    {
        $baseEnabled = [];
        $baseDiagnostics = [];
        $persistentDiagnostics = [];

        foreach ($this->entries as $index => $entry) {
            $extension = $entry['extension'];
            $descriptor = $extension instanceof CompilerExtension ? $extension->descriptor() : null;

            $diagnostics = [];
            if ($extension instanceof CompilerExtension) {
                $diagnostics = array_merge($diagnostics, $this->validator->validateExtension($extension));
            }

            $this->entries[$index]['descriptor'] = $descriptor;
            $this->entries[$index]['base_diagnostics'] = $diagnostics;
            $this->entries[$index]['diagnostics'] = [];
            $this->entries[$index]['enabled'] = false;
            $this->entries[$index]['load_order'] = null;
            $this->entries[$index]['lifecycle'] = $this->defaultLifecycle('discovered', 'pending');

            $baseDiagnostics[$index] = $diagnostics;
            $persistentDiagnostics[$index] = [];
            $baseEnabled[$index] = $extension instanceof CompilerExtension && !$this->hasErrorDiagnostics($diagnostics);
        }

        $firstByName = [];
        foreach ($this->entries as $index => $entry) {
            if (!$baseEnabled[$index]) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (!isset($firstByName[$name])) {
                $firstByName[$name] = $index;
                continue;
            }

            $first = $this->entries[$firstByName[$name]];
            $persistentDiagnostics[$index] = $this->appendDiagnostic(
                $persistentDiagnostics[$index],
                $this->diagnostic(
                    code: 'FDY7005_DUPLICATE_EXTENSION_ID',
                    message: sprintf(
                        'Extension %s is registered multiple times; keeping %s@%s from %s and disabling %s@%s from %s.',
                        $name,
                        (string) ($first['name'] ?? ''),
                        (string) ($first['version'] ?? ''),
                        (string) ($first['source_path'] ?? 'manual'),
                        (string) ($entry['name'] ?? ''),
                        (string) ($entry['version'] ?? ''),
                        (string) ($entry['source_path'] ?? 'manual'),
                    ),
                    extension: $name,
                ),
            );
            $baseEnabled[$index] = false;
        }

        while (true) {
            $resolution = $this->resolveRuntimeState($baseEnabled);
            if ($resolution['cycle_diagnostics'] === []) {
                break;
            }

            foreach ($resolution['cycle_diagnostics'] as $index => $diagnostics) {
                foreach ($diagnostics as $diagnostic) {
                    $persistentDiagnostics[$index] = $this->appendDiagnostic($persistentDiagnostics[$index], $diagnostic);
                }
                $baseEnabled[$index] = false;
            }
        }

        foreach ($this->entries as $index => $entry) {
            $transientDiagnostics = $resolution['diagnostics'][$index] ?? [];
            $diagnostics = array_merge(
                $baseDiagnostics[$index] ?? [],
                $persistentDiagnostics[$index] ?? [],
                $transientDiagnostics,
            );
            $enabled = (bool) ($resolution['enabled'][$index] ?? false);
            $loadOrder = $resolution['load_order'][$index] ?? null;

            $this->entries[$index]['diagnostics'] = $this->sortDiagnostics($diagnostics);
            $this->entries[$index]['enabled'] = $enabled;
            $this->entries[$index]['load_order'] = is_int($loadOrder) ? $loadOrder : null;
            $this->entries[$index]['lifecycle'] = $this->buildLifecycle($this->entries[$index]);
        }

        $this->registrationDiagnostics = $this->sortDiagnostics($this->registrationDiagnostics);
    }

    /**
     * @param array<int,bool> $baseEnabled
     * @return array{
     *   enabled:array<int,bool>,
     *   diagnostics:array<int,array<int,array<string,mixed>>>,
     *   load_order:array<int,int>,
     *   cycle_diagnostics:array<int,array<int,array<string,mixed>>>
     * }
     */
    private function resolveRuntimeState(array $baseEnabled): array
    {
        $enabled = $baseEnabled;
        $diagnostics = [];

        while (true) {
            $changed = false;
            $candidateIndexes = $this->enabledIndexes($enabled);

            $packOwners = [];
            foreach ($candidateIndexes as $index) {
                $extension = $this->entries[$index]['extension'];
                if (!$extension instanceof CompilerExtension) {
                    continue;
                }

                foreach ($extension->packs() as $pack) {
                    if (!$pack instanceof PackDefinition) {
                        continue;
                    }

                    if (!isset($packOwners[$pack->name])) {
                        $packOwners[$pack->name] = ['index' => $index, 'extension' => $extension->name()];
                        continue;
                    }

                    $owner = $packOwners[$pack->name];
                    $diagnostics[$index] = $this->appendDiagnostic(
                        $diagnostics[$index] ?? [],
                        $this->diagnostic(
                            code: 'FDY7021_DUPLICATE_PACK_ID',
                            message: sprintf(
                                'Pack %s is registered multiple times; keeping %s and disabling %s.',
                                $pack->name,
                                (string) ($owner['extension'] ?? 'unknown'),
                                $extension->name(),
                            ),
                            extension: $extension->name(),
                            pack: $pack->name,
                        ),
                    );
                    $enabled[$index] = false;
                    $changed = true;
                    break;
                }
            }

            if ($changed) {
                continue;
            }

            $candidateIndexes = $this->enabledIndexes($enabled);
            $nameIndexMap = $this->enabledNameIndexMap($enabled);

            foreach ($this->conflictPairs($candidateIndexes, $nameIndexMap) as $row) {
                $loser = (int) $row['loser'];
                $winner = (int) $row['winner'];
                if (!$enabled[$loser]) {
                    continue;
                }

                $loserName = (string) ($this->entries[$loser]['name'] ?? '');
                $winnerName = (string) ($this->entries[$winner]['name'] ?? '');

                $diagnostics[$loser] = $this->appendDiagnostic(
                    $diagnostics[$loser] ?? [],
                    $this->diagnostic(
                        code: 'FDY7015_EXTENSION_CONFLICT',
                        message: sprintf('Extension %s conflicts with %s; keeping %s and disabling %s.', $loserName, $winnerName, $winnerName, $loserName),
                        extension: $loserName,
                        details: ['conflicts_with' => $winnerName],
                    ),
                );
                $enabled[$loser] = false;
                $changed = true;
            }

            if ($changed) {
                continue;
            }

            $candidateIndexes = $this->enabledIndexes($enabled);
            $packOwners = $this->enabledPackOwners($enabled);
            $packConflictRows = $this->packConflictRows($candidateIndexes, $packOwners);
            foreach ($packConflictRows as $row) {
                $loser = (int) $row['loser'];
                if (!$enabled[$loser]) {
                    continue;
                }

                $extensionName = (string) ($this->entries[$loser]['name'] ?? '');
                $packName = (string) ($row['pack'] ?? '');
                $conflictingPack = (string) ($row['conflicting_pack'] ?? '');

                $diagnostics[$loser] = $this->appendDiagnostic(
                    $diagnostics[$loser] ?? [],
                    $this->diagnostic(
                        code: 'FDY7022_PACK_CONFLICT',
                        message: sprintf('Pack %s conflicts with %s; disabling extension %s.', $packName, $conflictingPack, $extensionName),
                        extension: $extensionName,
                        pack: $packName,
                        details: ['conflicts_with_pack' => $conflictingPack],
                    ),
                );
                $enabled[$loser] = false;
                $changed = true;
            }

            if ($changed) {
                continue;
            }

            $candidateIndexes = $this->enabledIndexes($enabled);
            $nameIndexMap = $this->enabledNameIndexMap($enabled);
            $packOwners = $this->enabledPackOwners($enabled);

            foreach ($candidateIndexes as $index) {
                $descriptor = $this->entries[$index]['descriptor'];
                if (!$descriptor instanceof ExtensionDescriptor) {
                    continue;
                }

                $extensionName = (string) ($this->entries[$index]['name'] ?? '');
                foreach ($this->sortedUniqueStrings($descriptor->requiredExtensions) as $dependency) {
                    if (isset($nameIndexMap[$dependency])) {
                        continue;
                    }

                    $diagnostics[$index] = $this->appendDiagnostic(
                        $diagnostics[$index] ?? [],
                        $this->diagnostic(
                            code: 'FDY7014_EXTENSION_DEPENDENCY_MISSING',
                            message: sprintf('Extension %s requires missing extension %s.', $extensionName, $dependency),
                            extension: $extensionName,
                            details: ['dependency' => $dependency],
                        ),
                    );
                    $enabled[$index] = false;
                    $changed = true;
                }

                if (!$enabled[$index]) {
                    continue;
                }

                $extension = $this->entries[$index]['extension'];
                if (!$extension instanceof CompilerExtension) {
                    continue;
                }

                foreach ($extension->packs() as $pack) {
                    if (!$pack instanceof PackDefinition) {
                        continue;
                    }

                    foreach ($this->sortedUniqueStrings($pack->dependencies) as $dependency) {
                        if (isset($packOwners[$dependency])) {
                            continue;
                        }

                        $diagnostics[$index] = $this->appendDiagnostic(
                            $diagnostics[$index] ?? [],
                            $this->diagnostic(
                                code: 'FDY7018_PACK_DEPENDENCY_MISSING',
                                message: sprintf('Extension %s requires missing pack dependency %s for %s.', $extensionName, $dependency, $pack->name),
                                extension: $extensionName,
                                pack: $pack->name,
                                details: ['dependency' => $dependency],
                            ),
                        );
                        $enabled[$index] = false;
                        $changed = true;
                    }

                    if (!$enabled[$index]) {
                        break;
                    }
                }
            }

            if (!$changed) {
                break;
            }
        }

        $cycleDiagnostics = [];
        $loadOrder = [];
        $cycleIndexes = $this->cycleIndexes($enabled);
        if ($cycleIndexes !== []) {
            $cycleNames = array_values(array_map(
                fn (int $index): string => (string) ($this->entries[$index]['name'] ?? $this->entries[$index]['class'] ?? ''),
                $cycleIndexes,
            ));
            sort($cycleNames);

            foreach ($cycleIndexes as $index) {
                $extensionName = (string) ($this->entries[$index]['name'] ?? $this->entries[$index]['class'] ?? '');
                $cycleDiagnostics[$index][] = $this->diagnostic(
                    code: 'FDY7019_EXTENSION_DEPENDENCY_CYCLE',
                    message: sprintf('Extension %s participates in a dependency cycle (%s).', $extensionName, implode(', ', $cycleNames)),
                    extension: $extensionName,
                    details: ['cycle' => $cycleNames],
                );
            }
        } else {
            $loadOrder = $this->resolveLoadOrder($enabled);
        }

        return [
            'enabled' => $enabled,
            'diagnostics' => $diagnostics,
            'load_order' => $loadOrder,
            'cycle_diagnostics' => $cycleDiagnostics,
        ];
    }

    /**
     * @return array<int,array{winner:int,loser:int}>
     */
    private function conflictPairs(array $candidateIndexes, array $nameIndexMap): array
    {
        $rows = [];
        $seen = [];

        foreach ($candidateIndexes as $index) {
            $descriptor = $this->entries[$index]['descriptor'];
            if (!$descriptor instanceof ExtensionDescriptor) {
                continue;
            }

            $name = (string) ($this->entries[$index]['name'] ?? '');
            foreach ($this->sortedUniqueStrings($descriptor->conflictsWithExtensions) as $conflictName) {
                $otherIndex = $nameIndexMap[$conflictName] ?? null;
                if (!is_int($otherIndex) || $otherIndex === $index) {
                    continue;
                }

                $pair = [$index, $otherIndex];
                sort($pair);
                $pairKey = implode(':', $pair);
                if (isset($seen[$pairKey])) {
                    continue;
                }
                $seen[$pairKey] = true;

                [$winner, $loser] = $this->winnerAndLoser($index, $otherIndex);
                $rows[] = ['winner' => $winner, 'loser' => $loser];
            }
        }

        return $rows;
    }

    /**
     * @param array<int,int> $candidateIndexes
     * @param array<string,array{index:int,pack:PackDefinition}> $packOwners
     * @return array<int,array{loser:int,pack:string,conflicting_pack:string}>
     */
    private function packConflictRows(array $candidateIndexes, array $packOwners): array
    {
        $rows = [];
        $seen = [];

        foreach ($candidateIndexes as $index) {
            $extension = $this->entries[$index]['extension'];
            if (!$extension instanceof CompilerExtension) {
                continue;
            }

            foreach ($extension->packs() as $pack) {
                if (!$pack instanceof PackDefinition) {
                    continue;
                }

                foreach ($this->sortedUniqueStrings($pack->conflictsWith) as $conflictingPack) {
                    $owner = $packOwners[$conflictingPack] ?? null;
                    if (!is_array($owner)) {
                        continue;
                    }

                    $otherIndex = (int) ($owner['index'] ?? -1);
                    if ($otherIndex < 0 || $otherIndex === $index) {
                        continue;
                    }

                    [$winner, $loser] = $this->winnerAndLoser($index, $otherIndex);
                    $loserPack = $loser === $index ? $pack->name : $conflictingPack;
                    $winnerPack = $loser === $index ? $conflictingPack : $pack->name;
                    $key = $loserPack . ':' . $winnerPack . ':' . $loser;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $rows[] = [
                        'loser' => $loser,
                        'pack' => $loserPack,
                        'conflicting_pack' => $winnerPack,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<int,bool> $enabled
     * @return array<int,int>
     */
    private function cycleIndexes(array $enabled): array
    {
        $order = $this->resolveLoadOrder($enabled);
        $candidateIndexes = $this->enabledIndexes($enabled);

        if (count($candidateIndexes) === count($order)) {
            return [];
        }

        $orderedIndexes = array_flip(array_keys($order));
        $cycles = [];
        foreach ($candidateIndexes as $index) {
            if (isset($orderedIndexes[$index])) {
                continue;
            }
            $cycles[] = $index;
        }

        usort($cycles, $this->compareEntryIndexes(...));

        return $cycles;
    }

    /**
     * @param array<int,bool> $enabled
     * @return array<int,int>
     */
    private function resolveLoadOrder(array $enabled): array
    {
        $indexes = $this->enabledIndexes($enabled);
        $nameIndexMap = $this->enabledNameIndexMap($enabled);
        $packOwners = $this->enabledPackOwners($enabled);
        $edges = [];
        $indegree = [];

        foreach ($indexes as $index) {
            $edges[$index] = [];
            $indegree[$index] = 0;
        }

        foreach ($indexes as $index) {
            $descriptor = $this->entries[$index]['descriptor'];
            if (!$descriptor instanceof ExtensionDescriptor) {
                continue;
            }

            $dependencies = [];
            foreach ($this->sortedUniqueStrings(array_merge($descriptor->requiredExtensions, $descriptor->optionalExtensions)) as $dependency) {
                $ownerIndex = $nameIndexMap[$dependency] ?? null;
                if (is_int($ownerIndex) && $ownerIndex !== $index) {
                    $dependencies[$ownerIndex] = true;
                }
            }

            $extension = $this->entries[$index]['extension'];
            if ($extension instanceof CompilerExtension) {
                foreach ($extension->packs() as $pack) {
                    if (!$pack instanceof PackDefinition) {
                        continue;
                    }

                    foreach ($this->sortedUniqueStrings(array_merge($pack->dependencies, $pack->optionalDependencies)) as $dependency) {
                        $owner = $packOwners[$dependency] ?? null;
                        if (!is_array($owner)) {
                            continue;
                        }

                        $ownerIndex = (int) ($owner['index'] ?? -1);
                        if ($ownerIndex >= 0 && $ownerIndex !== $index) {
                            $dependencies[$ownerIndex] = true;
                        }
                    }
                }
            }

            foreach (array_keys($dependencies) as $dependencyIndex) {
                if (isset($edges[$dependencyIndex][$index])) {
                    continue;
                }

                $edges[$dependencyIndex][$index] = true;
                $indegree[$index]++;
            }
        }

        $available = [];
        foreach ($indexes as $index) {
            if (($indegree[$index] ?? 0) === 0) {
                $available[] = $index;
            }
        }
        usort($available, $this->compareEntryIndexes(...));

        $order = [];
        while ($available !== []) {
            $index = array_shift($available);
            if (!is_int($index)) {
                continue;
            }

            $order[$index] = count($order) + 1;
            foreach (array_keys($edges[$index] ?? []) as $dependentIndex) {
                $indegree[$dependentIndex]--;
                if ($indegree[$dependentIndex] === 0) {
                    $available[] = $dependentIndex;
                    usort($available, $this->compareEntryIndexes(...));
                }
            }
        }

        return $order;
    }

    /**
     * @param array<int,bool> $enabled
     * @return array<int,int>
     */
    private function enabledIndexes(array $enabled): array
    {
        $indexes = [];
        foreach ($enabled as $index => $isEnabled) {
            if ($isEnabled) {
                $indexes[] = (int) $index;
            }
        }

        sort($indexes);

        return $indexes;
    }

    /**
     * @param array<int,bool> $enabled
     * @return array<string,int>
     */
    private function enabledNameIndexMap(array $enabled): array
    {
        $map = [];
        foreach ($this->enabledIndexes($enabled) as $index) {
            $name = (string) ($this->entries[$index]['name'] ?? '');
            if ($name !== '') {
                $map[$name] = $index;
            }
        }

        return $map;
    }

    /**
     * @param array<int,bool> $enabled
     * @return array<string,array{index:int,pack:PackDefinition}>
     */
    private function enabledPackOwners(array $enabled): array
    {
        $owners = [];
        foreach ($this->enabledIndexes($enabled) as $index) {
            $extension = $this->entries[$index]['extension'];
            if (!$extension instanceof CompilerExtension) {
                continue;
            }

            foreach ($extension->packs() as $pack) {
                if ($pack instanceof PackDefinition && !isset($owners[$pack->name])) {
                    $owners[$pack->name] = ['index' => $index, 'pack' => $pack];
                }
            }
        }

        return $owners;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function enabledEntries(): array
    {
        $entries = array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => (bool) ($entry['enabled'] ?? false),
        ));

        usort(
            $entries,
            static fn (array $a, array $b): int => ((int) ($a['load_order'] ?? PHP_INT_MAX) <=> (int) ($b['load_order'] ?? PHP_INT_MAX))
                ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
                ?: strcmp((string) ($a['version'] ?? ''), (string) ($b['version'] ?? ''))
                ?: strcmp((string) ($a['class'] ?? ''), (string) ($b['class'] ?? '')),
        );

        return $entries;
    }

    /**
     * @return array<int,CompilerPass>
     */
    private function stagePasses(CompilerExtension $extension, string $stage): array
    {
        return match ($stage) {
            'discovery' => $extension->discoveryPasses(),
            'normalize' => $extension->normalizePasses(),
            'link' => $extension->linkPasses(),
            'validate' => $extension->validatePasses(),
            'enrich' => $extension->enrichPasses(),
            'analyze' => $extension->analyzePasses(),
            'emit' => $extension->emitPasses(),
            default => [],
        };
    }

    /**
     * @param array<int,array<string,mixed>> $diagnostics
     * @return array<int,array<string,mixed>>
     */
    private function appendDiagnostic(array $diagnostics, array $diagnostic): array
    {
        $key = md5(json_encode($diagnostic, JSON_THROW_ON_ERROR));
        foreach ($diagnostics as $existing) {
            $existingKey = md5(json_encode($existing, JSON_THROW_ON_ERROR));
            if ($existingKey === $key) {
                return $diagnostics;
            }
        }

        $diagnostics[] = $diagnostic;

        return $diagnostics;
    }

    /**
     * @return array<string,mixed>
     */
    private function diagnostic(
        string $code,
        string $message,
        ?string $extension = null,
        ?string $pack = null,
        string $severity = 'error',
        array $details = [],
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'category' => 'extensions',
            'message' => $message,
            'extension' => $extension,
            'pack' => $pack,
            'details' => $details,
        ];
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function registrationDiagnostic(string $code, string $message, string $sourcePath, array $details = []): array
    {
        return [
            'code' => $code,
            'severity' => 'error',
            'category' => 'extensions',
            'message' => $message,
            'source_path' => $sourcePath,
            'details' => $details,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $diagnostics
     */
    private function hasErrorDiagnostics(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ((string) ($diagnostic['severity'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $diagnostics
     * @return array<int,array<string,mixed>>
     */
    private function sortDiagnostics(array $diagnostics): array
    {
        $unique = [];
        foreach ($diagnostics as $diagnostic) {
            if (!is_array($diagnostic)) {
                continue;
            }

            $key = md5(json_encode($diagnostic, JSON_THROW_ON_ERROR));
            $unique[$key] = $diagnostic;
        }

        $diagnostics = array_values($unique);
        usort(
            $diagnostics,
            static fn (array $a, array $b): int => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''))
                ?: strcmp((string) ($a['extension'] ?? ''), (string) ($b['extension'] ?? ''))
                ?: strcmp((string) ($a['pack'] ?? ''), (string) ($b['pack'] ?? ''))
                ?: strcmp((string) ($a['message'] ?? ''), (string) ($b['message'] ?? '')),
        );

        return $diagnostics;
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private function sortedUniqueStrings(array $values): array
    {
        $values = array_values(array_filter(array_map('strval', $values), static fn (string $value): bool => trim($value) !== ''));
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function winnerAndLoser(int $left, int $right): array
    {
        return $left <= $right
            ? [$left, $right]
            : [$right, $left];
    }

    private function compareEntryIndexes(int $left, int $right): int
    {
        $leftEntry = $this->entries[$left] ?? [];
        $rightEntry = $this->entries[$right] ?? [];

        return strcmp((string) ($leftEntry['name'] ?? ''), (string) ($rightEntry['name'] ?? ''))
            ?: strcmp((string) ($leftEntry['version'] ?? ''), (string) ($rightEntry['version'] ?? ''))
            ?: strcmp((string) ($leftEntry['class'] ?? ''), (string) ($rightEntry['class'] ?? ''))
            ?: ($left <=> $right);
    }

    private function compareInspectRows(array $left, array $right): int
    {
        return ((($left['enabled'] ?? false) ? 0 : 1) <=> (($right['enabled'] ?? false) ? 0 : 1))
            ?: ((int) ($left['load_order'] ?? PHP_INT_MAX) <=> (int) ($right['load_order'] ?? PHP_INT_MAX))
            ?: strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''))
            ?: strcmp((string) ($left['version'] ?? ''), (string) ($right['version'] ?? ''))
            ?: strcmp((string) ($left['class'] ?? ''), (string) ($right['class'] ?? ''))
            ?: strcmp((string) ($left['source_path'] ?? ''), (string) ($right['source_path'] ?? ''));
    }

    private function compareExtensions(CompilerExtension $left, CompilerExtension $right): int
    {
        return strcmp($left->name(), $right->name())
            ?: strcmp($left->version(), $right->version())
            ?: strcmp(get_class($left), get_class($right));
    }

    private function comparePacks(PackDefinition $left, PackDefinition $right): int
    {
        return strcmp($left->name, $right->name)
            ?: strcmp($left->version, $right->version)
            ?: strcmp($left->extension, $right->extension);
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultLifecycle(string $currentStage, string $defaultStatus): array
    {
        return [
            'current_stage' => $currentStage,
            'stages' => [
                'discovered' => $defaultStatus,
                'loaded' => $defaultStatus,
                'validated' => $defaultStatus,
                'graph_integrated' => $defaultStatus,
                'runtime_enabled' => $defaultStatus,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function buildLifecycle(array $entry): array
    {
        $stages = [
            'discovered' => 'completed',
            'loaded' => $entry['extension'] instanceof CompilerExtension ? 'completed' : 'failed',
            'validated' => 'skipped',
            'graph_integrated' => 'skipped',
            'runtime_enabled' => 'skipped',
        ];
        $currentStage = 'discovered';

        if (!($entry['extension'] instanceof CompilerExtension)) {
            return ['current_stage' => $currentStage, 'stages' => $stages];
        }

        $stages['validated'] = $this->hasErrorDiagnostics((array) ($entry['diagnostics'] ?? [])) ? 'failed' : 'completed';
        $currentStage = $stages['validated'] === 'completed' ? 'validated' : 'loaded';

        if ((bool) ($entry['enabled'] ?? false)) {
            $stages['graph_integrated'] = 'completed';
            $stages['runtime_enabled'] = 'completed';
            $currentStage = 'runtime_enabled';
        }

        return ['current_stage' => $currentStage, 'stages' => $stages];
    }
}
