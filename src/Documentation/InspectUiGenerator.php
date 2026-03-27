<?php

declare(strict_types=1);

namespace Foundry\Documentation;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\IR\GraphNode;
use Foundry\Support\Paths;

final class InspectUiGenerator
{
    public function __construct(private readonly Paths $paths) {}

    /**
     * @return array<string,mixed>
     */
    public function generate(ApplicationGraph $graph): array
    {
        $root = $this->paths->join('docs/inspect-ui');
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        $sections = [
            'features' => $this->rows($graph, 'feature', 'feature'),
            'routes' => $this->rows($graph, 'route', 'signature'),
            'schemas' => $this->rows($graph, 'schema', 'path'),
            'auth' => $this->rows($graph, 'permission', 'name'),
            'jobs' => $this->rows($graph, 'job', 'name'),
            'events' => $this->rows($graph, 'event', 'name'),
            'caches' => $this->rows($graph, 'cache', 'key'),
            'contexts' => $this->rows($graph, 'context_manifest', 'feature'),
        ];

        $written = [];
        foreach ($sections as $name => $rows) {
            $path = $root . '/' . $name . '.html';
            file_put_contents($path, $this->renderPage($name, $rows));
            $written[] = $path;
        }

        $index = $root . '/index.html';
        file_put_contents($index, $this->renderIndex(array_keys($sections)));
        $written[] = $index;

        sort($written);

        return [
            'root' => $root,
            'files' => $written,
            'sections' => array_keys($sections),
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function rows(ApplicationGraph $graph, string $type, string $field): array
    {
        $rows = [];
        foreach ($graph->nodesByType($type) as $node) {
            if (!$node instanceof GraphNode) {
                continue;
            }
            $payload = $node->payload();
            $rows[] = [
                'id' => $node->id(),
                'label' => (string) ($payload[$field] ?? $node->id()),
                'source' => $node->sourcePath(),
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')),
        );

        return $rows;
    }

    /**
     * @param array<int,array<string,string>> $rows
     */
    private function renderPage(string $name, array $rows): string
    {
        $title = ucfirst($name);
        $htmlRows = '';
        foreach ($rows as $row) {
            $id = htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES);
            $label = htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES);
            $source = htmlspecialchars((string) ($row['source'] ?? ''), ENT_QUOTES);
            $htmlRows .= "<tr><td>{$id}</td><td>{$label}</td><td>{$source}</td></tr>\n";
        }

        return "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>{$title}</title><style>body{font-family:ui-sans-serif,system-ui;padding:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f2f2f2}</style></head><body><h1>{$title}</h1><p><a href=\"index.html\">Back to index</a></p><table><thead><tr><th>ID</th><th>Label</th><th>Source</th></tr></thead><tbody>{$htmlRows}</tbody></table></body></html>";
    }

    /**
     * @param array<int,string> $sections
     */
    private function renderIndex(array $sections): string
    {
        sort($sections);
        $items = '';
        foreach ($sections as $section) {
            $safe = htmlspecialchars($section, ENT_QUOTES);
            $items .= "<li><a href=\"{$safe}.html\">{$safe}</a></li>\n";
        }

        return "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Foundry Inspect UI</title><style>body{font-family:ui-sans-serif,system-ui;padding:24px}</style></head><body><h1>Foundry Inspect UI</h1><p>Generated from compiled graph.</p><ul>{$items}</ul></body></html>";
    }
}
