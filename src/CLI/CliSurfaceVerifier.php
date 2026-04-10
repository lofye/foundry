<?php

declare(strict_types=1);

namespace Foundry\CLI;

use Foundry\Support\ApiSurfaceRegistry;

final class CliSurfaceVerifier
{
    /**
     * @param list<Command> $commands
     */
    public function __construct(
        private readonly ApiSurfaceRegistry $registry,
        private readonly array $commands,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $analysis = $this->analyze();

        return [
            'summary' => [
                'total_signatures' => $analysis['total_signatures'],
                'valid' => $analysis['valid'],
                'invalid' => count($analysis['details']['invalid']),
                'ambiguous' => count($analysis['details']['ambiguous']),
                'orphan_handlers' => count($analysis['details']['orphan_handlers']),
                'coverage' => $analysis['coverage'],
            ],
            'signatures' => $analysis['signatures'],
            'handlers' => $analysis['handlers'],
            'details' => $analysis['details'],
        ];
    }

    /**
     * @return array{
     *     total_signatures:int,
     *     valid:int,
     *     invalid:int,
     *     ambiguous:int,
     *     orphan_handlers:int,
     *     coverage:float,
     *     details:array{
     *         invalid:list<string>,
     *         ambiguous:list<string>,
     *         orphan_handlers:list<string>
     *     }
     * }
     */
    public function verify(): array
    {
        $analysis = $this->analyze();

        return [
            'total_signatures' => $analysis['total_signatures'],
            'valid' => $analysis['valid'],
            'invalid' => count($analysis['details']['invalid']),
            'ambiguous' => count($analysis['details']['ambiguous']),
            'orphan_handlers' => count($analysis['details']['orphan_handlers']),
            'coverage' => $analysis['coverage'],
            'details' => $analysis['details'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function analyze(): array
    {
        $entriesBySignature = [];
        foreach ($this->registry->cliCommands() as $entry) {
            $signature = trim((string) ($entry['signature'] ?? ''));
            if ($signature === '') {
                continue;
            }

            $entriesBySignature[$signature] ??= [];
            $entriesBySignature[$signature][] = $entry;
        }

        ksort($entriesBySignature);

        $specialCases = $this->specialCases();
        $invalid = [];
        $ambiguous = [];
        $valid = 0;
        $signatureRows = [];

        foreach ($entriesBySignature as $signature => $entries) {
            $handlerCandidates = $this->handlerCandidates($signature);
            $specialCase = $specialCases[$signature] ?? null;
            $candidateCount = count($handlerCandidates) + ($specialCase !== null ? 1 : 0);
            $dispatchProbe = $this->dispatchProbeStatus($signature, $handlerCandidates, $specialCase);

            $status = 'valid';
            if (count($entries) > 1 || $candidateCount > 1) {
                $status = 'ambiguous';
                $ambiguous[] = $signature;
            } elseif ($candidateCount === 0 || $dispatchProbe === false) {
                $status = 'invalid';
                $invalid[] = $signature;
            } else {
                $valid++;
            }

            $primary = $entries[0];
            $signatureRows[] = [
                'signature' => $signature,
                'group' => $this->groupForSignature($signature),
                'description' => (string) ($primary['summary'] ?? ''),
                'arguments' => $this->argumentsForUsage((string) ($primary['usage'] ?? '')),
                'options' => $this->optionsForUsage((string) ($primary['usage'] ?? '')),
                'usage' => (string) ($primary['usage'] ?? ''),
                'stability' => (string) ($primary['stability'] ?? 'internal'),
                'availability' => (string) ($primary['availability'] ?? 'core'),
                'handler' => $specialCase['handler'] ?? ($handlerCandidates[0]['handler'] ?? null),
                'dispatch' => $specialCase !== null ? 'special_case' : ($handlerCandidates !== [] ? 'handler' : null),
                'status' => $status,
                'registry_entry_count' => count($entries),
                'handler_candidates' => array_values(array_map(
                    static fn(array $candidate): string => (string) ($candidate['handler'] ?? ''),
                    $handlerCandidates,
                )),
            ];
        }

        $handlerRows = [];
        $orphanHandlers = [];
        foreach ($this->commands as $command) {
            $signatures = array_values(array_unique(array_values(array_filter(
                array_map('strval', $command->supportedSignatures()),
                static fn(string $signature): bool => trim($signature) !== '',
            ))));
            sort($signatures);

            $handler = $this->shortHandlerName($command);
            if ($signatures === []) {
                $orphanHandlers[] = $handler;
            }

            $handlerRows[] = [
                'handler' => $handler,
                'class' => $command::class,
                'signatures' => $signatures,
                'orphan' => $signatures === [],
            ];
        }

        usort(
            $handlerRows,
            static fn(array $a, array $b): int => strcmp((string) ($a['handler'] ?? ''), (string) ($b['handler'] ?? '')),
        );
        sort($invalid);
        sort($ambiguous);
        sort($orphanHandlers);

        $totalSignatures = count($entriesBySignature);
        $coverage = $totalSignatures === 0 ? 1.0 : round($valid / $totalSignatures, 4);

        return [
            'total_signatures' => $totalSignatures,
            'valid' => $valid,
            'coverage' => $coverage,
            'signatures' => $signatureRows,
            'handlers' => $handlerRows,
            'details' => [
                'invalid' => $invalid,
                'ambiguous' => $ambiguous,
                'orphan_handlers' => $orphanHandlers,
            ],
        ];
    }

    /**
     * @return array<string,array{handler:string}>
     */
    private function specialCases(): array
    {
        return [
            'help' => ['handler' => 'Application::helpResult'],
        ];
    }

    /**
     * @return list<array{handler:string,class:string}>
     */
    private function handlerCandidates(string $signature): array
    {
        $candidates = [];

        foreach ($this->commands as $command) {
            if (!$command->supportsSignature($signature)) {
                continue;
            }

            $candidates[] = [
                'handler' => $this->shortHandlerName($command),
                'class' => $command::class,
            ];
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => strcmp((string) ($a['handler'] ?? ''), (string) ($b['handler'] ?? '')),
        );

        return $candidates;
    }

    /**
     * @param list<array{handler:string,class:string}> $handlerCandidates
     * @param array{handler:string}|null $specialCase
     */
    private function dispatchProbeStatus(string $signature, array $handlerCandidates, ?array $specialCase): ?bool
    {
        if ($specialCase !== null) {
            return true;
        }

        if (count($handlerCandidates) !== 1) {
            return null;
        }

        $class = (string) $handlerCandidates[0]['class'];
        foreach ($this->commands as $command) {
            if ($command::class !== $class) {
                continue;
            }

            return $command->matches($this->probeArgsForSignature($signature));
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function probeArgsForSignature(string $signature): array
    {
        return match ($signature) {
            'codemod run' => ['codemod', 'run', 'example-codemod'],
            'examples:load' => ['examples:load', 'blog-api'],
            'inspect node' => ['inspect', 'node', 'feature:example'],
            'inspect dependencies' => ['inspect', 'dependencies', 'feature:example'],
            'inspect dependents' => ['inspect', 'dependents', 'feature:example'],
            'inspect impact' => ['inspect', 'impact', 'feature:example'],
            'inspect affected-tests' => ['inspect', 'affected-tests', 'feature:example'],
            'inspect affected-features' => ['inspect', 'affected-features', 'feature:example'],
            'inspect execution-plan' => ['inspect', 'execution-plan', 'example_feature'],
            'inspect extension' => ['inspect', 'extension', 'example'],
            'inspect pack' => ['inspect', 'pack', 'example'],
            'inspect definition-format' => ['inspect', 'definition-format', 'example'],
            'inspect subgraph' => ['inspect', 'subgraph', 'example_feature'],
            'inspect route' => ['inspect', 'route', 'GET', '/example'],
            'implement feature' => ['implement', 'feature', 'example-feature'],
            'implement spec' => ['implement', 'spec', 'example-feature/001-initial'],
            'plan feature' => ['plan', 'feature', 'example-feature'],
            default => array_values(array_filter(explode(' ', $signature), static fn(string $part): bool => $part !== '')),
        };
    }

    private function groupForSignature(string $signature): string
    {
        $parts = explode(' ', $signature);

        return (string) ($parts[0] ?? '');
    }

    /**
     * @return list<string>
     */
    private function argumentsForUsage(string $usage): array
    {
        preg_match_all('/<[^>]+>/', $usage, $matches);

        return $this->uniqueSortedStrings($matches[0] ?? []);
    }

    /**
     * @return list<string>
     */
    private function optionsForUsage(string $usage): array
    {
        preg_match_all('/--[a-z0-9:-]+(?:=<[^>]+>)?/i', $usage, $matches);

        return $this->uniqueSortedStrings($matches[0] ?? []);
    }

    /**
     * @param array<int,string> $values
     * @return list<string>
     */
    private function uniqueSortedStrings(array $values): array
    {
        $values = array_values(array_unique(array_values(array_filter(
            array_map('strval', $values),
            static fn(string $value): bool => trim($value) !== '',
        ))));
        sort($values);

        return $values;
    }

    private function shortHandlerName(Command $command): string
    {
        $parts = explode('\\', $command::class);

        return (string) end($parts);
    }
}
