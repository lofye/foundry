<?php
declare(strict_types=1);

namespace Foundry\Compiler\Diagnostics;

final class DiagnosticBag
{
    /**
     * @var array<int,Diagnostic>
     */
    private array $diagnostics = [];

    private int $sequence = 0;

    /**
     * @param array<int,string> $relatedNodes
     */
    public function add(
        string $code,
        string $severity,
        string $category,
        string $message,
        ?string $nodeId = null,
        ?string $sourcePath = null,
        ?int $sourceLine = null,
        array $relatedNodes = [],
        ?string $suggestedFix = null,
        ?string $pass = null,
    ): Diagnostic {
        $this->sequence++;
        $diagnostic = new Diagnostic(
            id: sprintf('D%04d', $this->sequence),
            code: $code,
            severity: $severity,
            category: $category,
            message: $message,
            nodeId: $nodeId,
            sourcePath: $sourcePath,
            sourceLine: $sourceLine,
            relatedNodes: array_values(array_unique(array_map('strval', $relatedNodes))),
            suggestedFix: $suggestedFix,
            pass: $pass,
        );

        $this->diagnostics[] = $diagnostic;

        return $diagnostic;
    }

    /**
     * @param array<int,string> $relatedNodes
     */
    public function error(
        string $code,
        string $category,
        string $message,
        ?string $nodeId = null,
        ?string $sourcePath = null,
        ?int $sourceLine = null,
        array $relatedNodes = [],
        ?string $suggestedFix = null,
        ?string $pass = null,
    ): Diagnostic {
        return $this->add($code, 'error', $category, $message, $nodeId, $sourcePath, $sourceLine, $relatedNodes, $suggestedFix, $pass);
    }

    /**
     * @param array<int,string> $relatedNodes
     */
    public function warning(
        string $code,
        string $category,
        string $message,
        ?string $nodeId = null,
        ?string $sourcePath = null,
        ?int $sourceLine = null,
        array $relatedNodes = [],
        ?string $suggestedFix = null,
        ?string $pass = null,
    ): Diagnostic {
        return $this->add($code, 'warning', $category, $message, $nodeId, $sourcePath, $sourceLine, $relatedNodes, $suggestedFix, $pass);
    }

    /**
     * @param array<int,string> $relatedNodes
     */
    public function info(
        string $code,
        string $category,
        string $message,
        ?string $nodeId = null,
        ?string $sourcePath = null,
        ?int $sourceLine = null,
        array $relatedNodes = [],
        ?string $suggestedFix = null,
        ?string $pass = null,
    ): Diagnostic {
        return $this->add($code, 'info', $category, $message, $nodeId, $sourcePath, $sourceLine, $relatedNodes, $suggestedFix, $pass);
    }

    /**
     * @return array<int,Diagnostic>
     */
    public function all(): array
    {
        return $this->diagnostics;
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{error:int,warning:int,info:int,total:int}
     */
    public function summary(): array
    {
        $summary = [
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'total' => 0,
        ];

        foreach ($this->diagnostics as $diagnostic) {
            $summary[$diagnostic->severity] = ($summary[$diagnostic->severity] ?? 0) + 1;
            $summary['total']++;
        }

        return $summary;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(),
            $this->diagnostics,
        );
    }

    /**
     * @return array<string,array<int,string>>
     */
    public function nodeDiagnosticMap(): array
    {
        $map = [];
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->nodeId !== null && $diagnostic->nodeId !== '') {
                $map[$diagnostic->nodeId] ??= [];
                $map[$diagnostic->nodeId][] = $diagnostic->id;
            }

            foreach ($diagnostic->relatedNodes as $related) {
                $map[$related] ??= [];
                $map[$related][] = $diagnostic->id;
            }
        }

        foreach ($map as &$ids) {
            sort($ids);
            $ids = array_values(array_unique($ids));
        }
        unset($ids);

        ksort($map);

        return $map;
    }
}
