<?php
declare(strict_types=1);

namespace Foundry\Documentation;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class DocsSiteBuilder
{
    private readonly MarkdownPageRenderer $renderer;
    private readonly GraphDocsGenerator $graphDocsGenerator;

    public function __construct(
        private readonly Paths $paths,
        private readonly ApiSurfaceRegistry $apiSurfaceRegistry,
    ) {
        $this->renderer = new MarkdownPageRenderer();
        $this->graphDocsGenerator = new GraphDocsGenerator($paths, $apiSurfaceRegistry);
    }

    /**
     * @return array<string,mixed>
     */
    public function build(ApplicationGraph $graph, ?string $currentVersion = null): array
    {
        $version = $this->normalizeVersion($currentVersion ?? $graph->frameworkVersion());
        $generated = $this->graphDocsGenerator->documents($graph);
        $currentPages = $this->loadCurrentPages($generated);
        $snapshotVersions = $this->loadSnapshotVersions();
        $versions = $this->versionRows($version, array_keys($snapshotVersions));
        $outputRoot = $this->paths->join('public/docs');

        $this->ensureDirectory($outputRoot);

        $currentBuild = $this->renderSite(
            outputRoot: $outputRoot,
            pages: $currentPages,
            versions: $versions,
            currentVersion: $version,
            siteVersion: $version,
            context: 'root',
        );

        $versionBuilds = [];
        $versionBuilds[] = $this->renderSite(
            outputRoot: $outputRoot . '/versions/' . $version,
            pages: $currentPages,
            versions: $versions,
            currentVersion: $version,
            siteVersion: $version,
            context: 'version',
        );

        foreach ($snapshotVersions as $snapshotVersion => $pages) {
            if ($snapshotVersion === $version) {
                continue;
            }

            $versionBuilds[] = $this->renderSite(
                outputRoot: $outputRoot . '/versions/' . $snapshotVersion,
                pages: $pages,
                versions: $versions,
                currentVersion: $version,
                siteVersion: $snapshotVersion,
                context: 'version',
            );
        }

        $versionsRoot = $outputRoot . '/versions';
        $this->ensureDirectory($versionsRoot);
        $this->writeAssets($versionsRoot);
        file_put_contents(
            $versionsRoot . '/index.html',
            $this->renderVersionsIndex($versions, $version),
        );

        $manifest = [
            'current_version' => $version,
            'versions' => $versions,
            'root' => $outputRoot,
            'sections' => $currentBuild['sections'],
            'pages' => $currentBuild['pages'],
        ];

        file_put_contents($outputRoot . '/manifest.json', Json::encode($manifest, true) . "\n");
        file_put_contents($outputRoot . '/versions.json', Json::encode(['versions' => $versions], true) . "\n");

        return [
            'output_root' => $outputRoot,
            'current_version' => $version,
            'versions' => $versions,
            'current' => $currentBuild,
            'versioned' => $versionBuilds,
            'manifest' => $outputRoot . '/manifest.json',
            'versions_index' => $versionsRoot . '/index.html',
        ];
    }

    /**
     * @param array<string,string> $generated
     * @return array<string,array<string,mixed>>
     */
    private function loadCurrentPages(array $generated): array
    {
        $pages = [];

        foreach ($this->pageCatalog() as $index => $spec) {
            $source = (string) ($spec['source'] ?? '');
            $page = [
                'slug' => (string) ($spec['slug'] ?? ''),
                'title' => (string) ($spec['title'] ?? ''),
                'section' => (string) ($spec['section'] ?? 'Reference'),
                'main_navigation' => (bool) ($spec['main_navigation'] ?? false),
                'order' => $index,
            ];

            if ($page['slug'] === '' || $page['title'] === '' || $source === '') {
                continue;
            }

            if (str_starts_with($source, 'generated:')) {
                $key = substr($source, strlen('generated:'));
                if (!isset($generated[$key])) {
                    continue;
                }

                $page['type'] = 'markdown';
                $page['source_path'] = 'generated/' . $key . '.md';
                $page['content'] = $generated[$key];
                $pages[$page['slug']] = $page;
                continue;
            }

            $path = $this->paths->join($source);
            if (!is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $page['type'] = strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'html' ? 'html' : 'markdown';
            $page['source_path'] = $path;
            $page['content'] = $content;
            $pages[$page['slug']] = $page;
        }

        foreach ($this->loadExamplePages() as $slug => $page) {
            $pages[$slug] = $page;
        }

        return $pages;
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function loadSnapshotVersions(): array
    {
        $root = $this->paths->join('docs/versions');
        if (!is_dir($root)) {
            return [];
        }

        $catalog = $this->catalogBySlug();
        $versions = [];
        $entries = scandir($root);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $directory = $root . '/' . $entry;
            if (!is_dir($directory)) {
                continue;
            }

            $pages = [];
            foreach ($this->discoverDocsFiles($directory) as $file) {
                $relative = substr($file, strlen($directory) + 1);
                $slug = $this->snapshotSlug($relative);
                $catalogEntry = $catalog[$slug] ?? [];
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                $pages[$slug] = [
                    'slug' => $slug,
                    'title' => (string) ($catalogEntry['title'] ?? $this->titleFromSource($file, $slug)),
                    'section' => (string) ($catalogEntry['section'] ?? 'Archived'),
                    'main_navigation' => (bool) ($catalogEntry['main_navigation'] ?? in_array($slug, ['index', 'quick-tour', 'how-it-works', 'reference'], true)),
                    'order' => (int) ($catalogEntry['order'] ?? (100 + count($pages))),
                    'type' => strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) === 'html' ? 'html' : 'markdown',
                    'source_path' => $file,
                    'content' => $content,
                ];
            }

            if ($pages === []) {
                continue;
            }

            $versions[$this->normalizeVersion($entry)] = $pages;
        }

        uksort($versions, fn (string $a, string $b): int => $this->compareVersions($a, $b));

        return $versions;
    }

    /**
     * @param array<int,array<string,mixed>> $versions
     * @param array<string,array<string,mixed>> $pages
     * @return array<string,mixed>
     */
    private function renderSite(
        string $outputRoot,
        array $pages,
        array $versions,
        string $currentVersion,
        string $siteVersion,
        string $context,
    ): array {
        $this->ensureDirectory($outputRoot);
        $this->writeAssets($outputRoot);

        $orderedPages = $this->orderedPages($pages);
        $sections = $this->sections($orderedPages);
        $linkMap = $this->linkMap($orderedPages);
        $written = [];
        $pageRows = [];

        foreach ($orderedPages as $page) {
            $html = $page['type'] === 'html'
                ? (string) $page['content']
                : $this->renderer->render($this->rewriteMarkdownLinks((string) $page['content'], $linkMap));
            $path = $outputRoot . '/' . $this->pageFilename((string) $page['slug']);

            file_put_contents(
                $path,
                $this->renderPage(
                    page: $page,
                    html: $html,
                    sections: $sections,
                    versions: $versions,
                    currentVersion: $currentVersion,
                    siteVersion: $siteVersion,
                    context: $context,
                ),
            );

            $written[] = $path;
            $pageRows[] = [
                'slug' => $page['slug'],
                'title' => $page['title'],
                'section' => $page['section'],
                'path' => $this->pageFilename((string) $page['slug']),
            ];
        }

        $manifest = [
            'version' => $siteVersion,
            'current_version' => $currentVersion,
            'pages' => $pageRows,
            'sections' => $sections,
        ];
        file_put_contents($outputRoot . '/manifest.json', Json::encode($manifest, true) . "\n");

        return [
            'version' => $siteVersion,
            'root' => $outputRoot,
            'files' => $written,
            'pages' => $pageRows,
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,array<int,array<string,mixed>>> $sections
     * @param array<int,array<string,mixed>> $versions
     */
    private function renderPage(
        array $page,
        string $html,
        array $sections,
        array $versions,
        string $currentVersion,
        string $siteVersion,
        string $context,
    ): string {
        $mainNav = $this->renderMainNav((string) $page['slug'], $currentVersion, $siteVersion, $context, $sections);
        $sideNav = $this->renderSideNav((string) $page['section'], (string) $page['slug'], $sections);
        $versionLinks = $this->renderVersionLinks($versions, $currentVersion, $siteVersion, $context);
        $versionLabel = htmlspecialchars($siteVersion, ENT_QUOTES);
        $title = htmlspecialchars((string) $page['title'], ENT_QUOTES);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title} | Foundry Docs</title>
  <link rel="stylesheet" href="assets/site.css">
</head>
<body>
  <div class="shell">
    <header class="site-header">
      <div class="brand">
        <a href="index.html">Foundry Docs</a>
        <span class="version-pill">{$versionLabel}</span>
      </div>
      {$mainNav}
    </header>
    <div class="version-strip">
      {$versionLinks}
    </div>
    <div class="layout">
      <aside class="side-nav">
        {$sideNav}
      </aside>
      <main class="content">
        {$html}
      </main>
    </div>
  </div>
</body>
</html>
HTML;
    }

    /**
     * @param array<int,array<string,mixed>> $versions
     */
    private function renderVersionsIndex(array $versions, string $currentVersion): string
    {
        $cards = [];
        foreach ($versions as $version) {
            $name = (string) ($version['version'] ?? '');
            $current = (bool) ($version['current'] ?? false);
            $href = $current ? '../index.html' : $name . '/index.html';
            $badge = $current ? '<span class="version-pill">Current</span>' : '';
            $cards[] = '<a class="version-card" href="' . htmlspecialchars($href, ENT_QUOTES) . '"><strong>'
                . htmlspecialchars($name, ENT_QUOTES) . '</strong>' . $badge
                . '<span>Framework tag: ' . htmlspecialchars((string) ($version['tag'] ?? $name), ENT_QUOTES) . '</span></a>';
        }

        $cardsHtml = implode("\n", $cards);
        $currentLabel = htmlspecialchars($currentVersion, ENT_QUOTES);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Versions | Foundry Docs</title>
  <link rel="stylesheet" href="assets/site.css">
</head>
<body>
  <div class="shell versions-shell">
    <header class="site-header">
      <div class="brand">
        <a href="../index.html">Foundry Docs</a>
        <span class="version-pill">{$currentLabel}</span>
      </div>
      <nav class="top-nav">
        <a href="../index.html">Intro</a>
        <a href="../quick-tour.html">Quick Tour</a>
        <a href="../how-it-works.html">How It Works</a>
        <a href="../reference.html">Reference</a>
        <a class="active" href="index.html">Versions</a>
      </nav>
    </header>
    <main class="versions-grid">
      {$cardsHtml}
    </main>
  </div>
</body>
</html>
HTML;
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $sections
     */
    private function renderMainNav(
        string $currentSlug,
        string $currentVersion,
        string $siteVersion,
        string $context,
        array $sections,
    ): string {
        $mainPages = ['index' => 'Intro', 'quick-tour' => 'Quick Tour', 'how-it-works' => 'How It Works', 'reference' => 'Reference'];
        $links = [];

        foreach ($mainPages as $slug => $label) {
            $href = $this->pageFilename($slug);
            $class = $currentSlug === $slug ? ' class="active"' : '';
            $links[] = '<a' . $class . ' href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        }

        $versionsHref = match ($context) {
            'root' => 'versions/index.html',
            'version' => '../index.html',
            default => 'index.html',
        };
        $versionsClass = $context === 'versions' ? ' class="active"' : '';
        $links[] = '<a' . $versionsClass . ' href="' . htmlspecialchars($versionsHref, ENT_QUOTES) . '">Versions</a>';

        return '<nav class="top-nav">' . implode("\n", $links) . '</nav>';
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $sections
     */
    private function renderSideNav(string $currentSection, string $currentSlug, array $sections): string
    {
        $pages = $sections[$currentSection] ?? [];
        $links = ['<p class="side-title">' . htmlspecialchars($currentSection, ENT_QUOTES) . '</p>'];

        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            $class = $slug === $currentSlug ? ' class="active"' : '';
            $links[] = '<a' . $class . ' href="' . htmlspecialchars($this->pageFilename($slug), ENT_QUOTES) . '">'
                . htmlspecialchars((string) ($page['title'] ?? $slug), ENT_QUOTES) . '</a>';
        }

        return implode("\n", $links);
    }

    /**
     * @param array<int,array<string,mixed>> $versions
     */
    private function renderVersionLinks(array $versions, string $currentVersion, string $siteVersion, string $context): string
    {
        $links = [];
        foreach ($versions as $version) {
            $name = (string) ($version['version'] ?? '');
            $href = $this->versionHref($name, $currentVersion, $context);
            $class = $name === $siteVersion && $context !== 'root' ? ' class="active"' : '';
            if ($name === $currentVersion && $context === 'root') {
                $class = ' class="active"';
            }

            $links[] = '<a' . $class . ' href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
                . htmlspecialchars($name, ENT_QUOTES) . '</a>';
        }

        return implode("\n", $links);
    }

    private function versionHref(string $targetVersion, string $currentVersion, string $context): string
    {
        if ($targetVersion === $currentVersion) {
            return match ($context) {
                'root' => 'index.html',
                'version' => '../../index.html',
                default => '../index.html',
            };
        }

        return match ($context) {
            'root' => 'versions/' . $targetVersion . '/index.html',
            'version' => '../' . $targetVersion . '/index.html',
            default => $targetVersion . '/index.html',
        };
    }

    /**
     * @param array<string,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private function orderedPages(array $pages): array
    {
        $ordered = array_values($pages);
        usort(
            $ordered,
            static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0))
                ?: strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')),
        );

        return $ordered;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function sections(array $pages): array
    {
        $sections = [];
        foreach ($pages as $page) {
            $section = (string) ($page['section'] ?? 'Reference');
            $sections[$section] ??= [];
            $sections[$section][] = [
                'slug' => $page['slug'],
                'title' => $page['title'],
            ];
        }

        return $sections;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,string>
     */
    private function linkMap(array $pages): array
    {
        $map = [];
        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            $filename = $this->pageFilename($slug);
            $sourcePath = (string) ($page['source_path'] ?? '');

            if ($sourcePath !== '') {
                $basename = basename($sourcePath);
                if ($basename !== 'README.md' || str_contains(str_replace('\\', '/', $sourcePath), '/docs/versions/')) {
                    $map[$basename] = $filename;
                }

                $normalized = str_replace('\\', '/', $sourcePath);
                if (str_contains($normalized, '/docs/')) {
                    $map[substr($normalized, strpos($normalized, '/docs/') + 1)] = $filename;
                } elseif (str_contains($normalized, '/examples/')) {
                    $relative = ltrim(substr($normalized, strpos($normalized, '/examples/')), '/');
                    $map[$relative] = $filename;
                    $map['../' . $relative] = $filename;
                } else {
                    $map[$normalized] = $filename;
                }
            }

            $map[$slug . '.md'] = $filename;
            $map[$slug . '.html'] = $filename;
        }

        $map['intro.md'] = 'index.html';

        return $map;
    }

    private function rewriteMarkdownLinks(string $markdown, array $linkMap): string
    {
        return preg_replace_callback(
            '/\[(.+?)\]\(([^)#?]+)\)/',
            static function (array $matches) use ($linkMap): string {
                $href = (string) $matches[2];
                if (str_contains($href, '://') || str_starts_with($href, '#')) {
                    return $matches[0];
                }

                $normalized = str_replace('\\', '/', $href);
                $basename = basename($normalized);
                $resolved = $linkMap[$normalized] ?? $linkMap[$basename] ?? null;
                if ($resolved === null) {
                    return $matches[0];
                }

                return '[' . $matches[1] . '](' . $resolved . ')';
            },
            $markdown,
        ) ?? $markdown;
    }

    private function pageFilename(string $slug): string
    {
        return $slug === 'index' ? 'index.html' : $slug . '.html';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function pageCatalog(): array
    {
        return [
            ['slug' => 'index', 'title' => 'Intro', 'section' => 'Getting Started', 'source' => 'docs/intro.md', 'main_navigation' => true],
            ['slug' => 'quick-tour', 'title' => 'Quick Tour', 'section' => 'Getting Started', 'source' => 'docs/quick-tour.md', 'main_navigation' => true],
            ['slug' => 'app-scaffolding', 'title' => 'App Scaffolding', 'section' => 'Getting Started', 'source' => 'docs/app-scaffolding.md'],
            ['slug' => 'example-applications', 'title' => 'Example Applications', 'section' => 'Getting Started', 'source' => 'docs/example-applications.md'],
            ['slug' => 'how-it-works', 'title' => 'How It Works', 'section' => 'Architecture', 'source' => 'docs/how-it-works.md', 'main_navigation' => true],
            ['slug' => 'semantic-compiler', 'title' => 'Semantic Compiler', 'section' => 'Architecture', 'source' => 'docs/semantic-compiler.md'],
            ['slug' => 'execution-pipeline', 'title' => 'Execution Pipeline', 'section' => 'Architecture', 'source' => 'docs/execution-pipeline.md'],
            ['slug' => 'architecture-tools', 'title' => 'Architecture Tools', 'section' => 'Architecture', 'source' => 'docs/architecture-tools.md'],
            ['slug' => 'contributor-vocabulary', 'title' => 'Contributor Vocabulary', 'section' => 'Architecture', 'source' => 'docs/contributor-vocabulary.md'],
            ['slug' => 'reference', 'title' => 'Reference', 'section' => 'Reference', 'source' => 'docs/reference.md', 'main_navigation' => true],
            ['slug' => 'graph-overview', 'title' => 'Graph Overview', 'section' => 'Reference', 'source' => 'generated:graph-overview'],
            ['slug' => 'features', 'title' => 'Feature Catalog', 'section' => 'Reference', 'source' => 'generated:features'],
            ['slug' => 'routes', 'title' => 'Route Catalog', 'section' => 'Reference', 'source' => 'generated:routes'],
            ['slug' => 'auth', 'title' => 'Auth Matrix', 'section' => 'Reference', 'source' => 'generated:auth'],
            ['slug' => 'events', 'title' => 'Event Registry', 'section' => 'Reference', 'source' => 'generated:events'],
            ['slug' => 'jobs', 'title' => 'Job Registry', 'section' => 'Reference', 'source' => 'generated:jobs'],
            ['slug' => 'caches', 'title' => 'Cache Registry', 'section' => 'Reference', 'source' => 'generated:caches'],
            ['slug' => 'schemas', 'title' => 'Schema Catalog', 'section' => 'Reference', 'source' => 'generated:schemas'],
            ['slug' => 'cli-reference', 'title' => 'CLI Reference', 'section' => 'Reference', 'source' => 'generated:cli-reference'],
            ['slug' => 'api-surface', 'title' => 'API Surface Policy', 'section' => 'Reference', 'source' => 'generated:api-surface'],
            ['slug' => 'upgrade-reference', 'title' => 'Upgrade Reference', 'section' => 'Reference', 'source' => 'generated:upgrade-reference'],
            ['slug' => 'llm-workflow', 'title' => 'LLM Workflow', 'section' => 'Reference', 'source' => 'generated:llm-workflow'],
            ['slug' => 'public-api-policy', 'title' => 'Public API Policy', 'section' => 'Extensions', 'source' => 'docs/public-api-policy.md'],
            ['slug' => 'extension-author-guide', 'title' => 'Extension Author Guide', 'section' => 'Extensions', 'source' => 'docs/extension-author-guide.md'],
            ['slug' => 'extensions-and-migrations', 'title' => 'Extensions And Migrations', 'section' => 'Extensions', 'source' => 'docs/extensions-and-migrations.md'],
            ['slug' => 'upgrade-safety', 'title' => 'Upgrade Safety', 'section' => 'Extensions', 'source' => 'docs/upgrade-safety.md'],
            ['slug' => 'api-notifications-docs', 'title' => 'API And Notifications', 'section' => 'Extensions', 'source' => 'docs/api-notifications-docs.md'],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadExamplePages(): array
    {
        $root = $this->paths->join('examples');
        if (!is_dir($root)) {
            return [];
        }

        $pages = [];
        $readmes = glob($root . '/*/README.md') ?: [];
        sort($readmes);

        foreach (array_values($readmes) as $index => $readme) {
            $content = file_get_contents($readme);
            if ($content === false) {
                continue;
            }

            $directory = basename((string) dirname($readme));
            $slug = 'example-' . strtolower($directory);
            $pages[$slug] = [
                'slug' => $slug,
                'title' => $this->titleFromSource($readme, $directory),
                'section' => 'Examples',
                'main_navigation' => false,
                'order' => 700 + $index,
                'type' => 'markdown',
                'source_path' => $readme,
                'content' => $content,
            ];
        }

        return $pages;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function catalogBySlug(): array
    {
        $bySlug = [];
        foreach ($this->pageCatalog() as $order => $page) {
            $page['order'] = $order;
            $bySlug[(string) $page['slug']] = $page;
        }

        return $bySlug;
    }

    /**
     * @return array<int,string>
     */
    private function discoverDocsFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }

            $extension = strtolower($item->getExtension());
            if (!in_array($extension, ['md', 'html'], true)) {
                continue;
            }

            $files[] = $item->getPathname();
        }

        sort($files);

        return $files;
    }

    private function snapshotSlug(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $basename = pathinfo(basename($normalized), PATHINFO_FILENAME);
        $basename = strtolower($basename);

        if ($basename === 'intro' || $basename === 'readme' || $basename === 'index') {
            return 'index';
        }

        $catalog = $this->catalogBySlug();
        if (isset($catalog[$basename])) {
            return $basename;
        }

        return str_replace('/', '-', strtolower((string) pathinfo($normalized, PATHINFO_FILENAME)));
    }

    private function titleFromSource(string $path, string $fallbackSlug): string
    {
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return $this->humanizeSlug($fallbackSlug);
        }

        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'html') {
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $content, $matches) === 1) {
                return trim(strip_tags((string) $matches[1]));
            }
        } else {
            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                if (str_starts_with($line, '# ')) {
                    return trim(substr($line, 2));
                }
            }
        }

        return $this->humanizeSlug($fallbackSlug);
    }

    private function humanizeSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * @param array<int,string> $snapshotVersions
     * @return array<int,array<string,mixed>>
     */
    private function versionRows(string $currentVersion, array $snapshotVersions): array
    {
        $rows = [[
            'version' => $currentVersion,
            'tag' => $currentVersion,
            'current' => true,
        ]];

        $snapshotVersions = array_values(array_unique(array_filter(
            array_map([$this, 'normalizeVersion'], $snapshotVersions),
            static fn (string $version): bool => $version !== '',
        )));
        usort($snapshotVersions, fn (string $a, string $b): int => $this->compareVersions($a, $b));

        foreach ($snapshotVersions as $version) {
            if ($version === $currentVersion) {
                continue;
            }

            $rows[] = [
                'version' => $version,
                'tag' => $version,
                'current' => false,
            ];
        }

        return $rows;
    }

    private function compareVersions(string $left, string $right): int
    {
        $leftSemver = $this->semverComparable($left);
        $rightSemver = $this->semverComparable($right);

        if ($leftSemver !== null && $rightSemver !== null) {
            return version_compare($rightSemver, $leftSemver);
        }

        return strcmp($right, $left);
    }

    private function semverComparable(string $version): ?string
    {
        $trimmed = ltrim($version, 'v');

        return preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?$/', $trimmed) === 1 ? $trimmed : null;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return 'dev-main';
        }

        if (preg_match('/^v\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?$/', $version) === 1) {
            return $version;
        }

        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?$/', $version) === 1) {
            return 'v' . $version;
        }

        return $version;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function writeAssets(string $root): void
    {
        $assets = $root . '/assets';
        $this->ensureDirectory($assets);
        file_put_contents($assets . '/site.css', $this->styles());
    }

    private function styles(): string
    {
        return <<<CSS
:root {
  --bg: #f4efe6;
  --bg-strong: #e7dcc8;
  --panel: rgba(255, 252, 247, 0.86);
  --ink: #1f2a26;
  --muted: #5f6b66;
  --accent: #875c2f;
  --border: rgba(31, 42, 38, 0.14);
  --shadow: 0 18px 40px rgba(58, 42, 19, 0.08);
}

* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
  background:
    radial-gradient(circle at top left, rgba(135, 92, 47, 0.16), transparent 28rem),
    linear-gradient(180deg, #fbf7ef 0%, var(--bg) 100%);
  color: var(--ink);
  font-family: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Georgia, serif;
  line-height: 1.65;
}

a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

.shell {
  max-width: 1280px;
  margin: 0 auto;
  padding: 32px 20px 48px;
}

.site-header,
.layout,
.version-strip,
.version-card {
  backdrop-filter: blur(8px);
}

.site-header {
  display: flex;
  gap: 20px;
  align-items: center;
  justify-content: space-between;
  padding: 18px 22px;
  border: 1px solid var(--border);
  border-radius: 22px;
  background: var(--panel);
  box-shadow: var(--shadow);
}

.brand {
  display: flex;
  gap: 12px;
  align-items: center;
  font-size: 1.05rem;
  font-weight: 700;
}

.brand a { color: var(--ink); }

.version-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--bg-strong);
  color: var(--muted);
  font-size: 0.88rem;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.top-nav,
.version-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.top-nav a,
.version-strip a,
.side-nav a {
  border-radius: 999px;
  padding: 8px 12px;
  color: var(--muted);
}

.top-nav a.active,
.version-strip a.active,
.side-nav a.active {
  background: var(--ink);
  color: #fff7ef;
}

.version-strip {
  margin-top: 16px;
  padding: 12px 16px;
  border: 1px solid var(--border);
  border-radius: 18px;
  background: rgba(255, 252, 247, 0.72);
}

.layout {
  margin-top: 20px;
  display: grid;
  grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
  gap: 18px;
}

.side-nav,
.content,
.version-card {
  border: 1px solid var(--border);
  border-radius: 24px;
  background: var(--panel);
  box-shadow: var(--shadow);
}

.side-nav {
  padding: 18px 14px;
  align-self: start;
  position: sticky;
  top: 20px;
}

.side-nav a {
  display: block;
  margin-bottom: 6px;
}

.side-title {
  margin: 0 0 12px;
  padding: 0 10px;
  color: var(--muted);
  font-size: 0.82rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.content {
  padding: 28px 32px;
}

.content h1,
.content h2,
.content h3 {
  line-height: 1.18;
  margin-top: 0;
}

.content h2,
.content h3 {
  margin-top: 1.75em;
}

.content code {
  font-family: "IBM Plex Mono", "SFMono-Regular", Menlo, Consolas, monospace;
  font-size: 0.92em;
  background: rgba(31, 42, 38, 0.07);
  padding: 0.15em 0.4em;
  border-radius: 6px;
}

.content pre {
  overflow-x: auto;
  padding: 16px 18px;
  background: #1f2423;
  color: #f7f2ea;
  border-radius: 18px;
}

.content pre code {
  background: transparent;
  color: inherit;
  padding: 0;
}

.content ul,
.content ol {
  padding-left: 22px;
}

.versions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-top: 24px;
}

.version-card {
  display: grid;
  gap: 10px;
  padding: 20px;
  color: var(--ink);
}

.version-card span {
  color: var(--muted);
}

@media (max-width: 920px) {
  .layout {
    grid-template-columns: 1fr;
  }

  .side-nav {
    position: static;
  }

  .site-header {
    align-items: flex-start;
    flex-direction: column;
  }
}
CSS;
    }
}
