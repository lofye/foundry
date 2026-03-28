<?php

declare(strict_types=1);

namespace Foundry\Documentation;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Compiler\Diagnostics\DiagnosticBag;
use Foundry\Compiler\Extensions\ExtensionRegistry;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Json;
use Foundry\Support\Paths;

/**
 * Deprecated legacy local preview builder. Public docs rendering/publishing lives in the website repo.
 */
final class DocsSiteBuilder
{
    private const BUILD_MODE = 'legacy_local_preview';
    private const PREVIEW_NOTICE = 'Legacy local preview only. Author canonical docs in framework/docs; render and publish public docs from the website repo.';
    private const SNAPSHOT_NOTICE = 'docs/versions is deprecated as a publishing source. The website repo owns authoritative published version snapshots.';

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
        $currentPages['architecture-explorer'] = $this->architectureExplorerPage($graph);
        $snapshotVersions = $this->loadSnapshotVersions();
        $versions = $this->versionRows($version, array_keys($snapshotVersions));
        $outputRoot = $this->paths->join('public/docs');
        $previewMetadata = $this->legacyPreviewMetadata();

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
            'mode' => self::BUILD_MODE,
            'deprecation' => $previewMetadata,
            'current_version' => $version,
            'versions' => $versions,
            'root' => $outputRoot,
            'sections' => $currentBuild['sections'],
            'pages' => $currentBuild['pages'],
        ];

        file_put_contents($outputRoot . '/manifest.json', Json::encode($manifest, true) . "\n");
        file_put_contents($outputRoot . '/versions.json', Json::encode([
            'mode' => self::BUILD_MODE,
            'deprecation' => $previewMetadata,
            'versions' => $versions,
        ], true) . "\n");

        return [
            'mode' => self::BUILD_MODE,
            'deprecation' => $previewMetadata,
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
     * @return array<string,mixed>
     */
    private function architectureExplorerPage(ApplicationGraph $graph): array
    {
        $catalog = $this->catalogBySlug()['architecture-explorer'] ?? [];

        return [
            'slug' => 'architecture-explorer',
            'title' => (string) ($catalog['title'] ?? 'Architecture Explorer'),
            'section' => (string) ($catalog['section'] ?? 'Architecture'),
            'main_navigation' => false,
            'order' => (int) ($catalog['order'] ?? 0),
            'type' => 'html',
            'source_path' => 'generated/architecture-explorer.html',
            'content' => $this->architectureExplorerContent($graph),
        ];
    }

    private function architectureExplorerContent(ApplicationGraph $graph): string
    {
        $dataJson = htmlspecialchars(Json::encode($this->architectureExplorerData($graph), true), ENT_NOQUOTES);

        $template = <<<'HTML'
<section class="architecture-explorer" id="architecture-explorer">
  <div class="architecture-explorer__header">
    <h1>Architecture Explorer</h1>
    <p>Read-only, deterministic graph view generated from the compiled Foundry graph JSON. Search highlights matching nodes, filters narrow the view, and selecting a node reveals dependencies, dependents, and related docs.</p>
  </div>

  <div class="architecture-explorer__toolbar">
    <label class="architecture-explorer__field">
      <span>Search</span>
      <input id="architecture-explorer-search" type="search" placeholder="Search node name, type, or label">
    </label>
    <label class="architecture-explorer__field">
      <span>Extension</span>
      <select id="architecture-explorer-extension">
        <option value="">All extensions</option>
      </select>
    </label>
    <label class="architecture-explorer__field">
      <span>Pipeline Stage</span>
      <select id="architecture-explorer-pipeline">
        <option value="">All stages</option>
      </select>
    </label>
  </div>

  <fieldset class="architecture-explorer__types">
    <legend>Type Filters</legend>
    <label><input type="checkbox" name="architecture-node-type" value="feature"> Feature</label>
    <label><input type="checkbox" name="architecture-node-type" value="route"> Route</label>
    <label><input type="checkbox" name="architecture-node-type" value="workflow"> Workflow</label>
    <label><input type="checkbox" name="architecture-node-type" value="event"> Event</label>
    <label><input type="checkbox" name="architecture-node-type" value="schema"> Schema</label>
    <label><input type="checkbox" name="architecture-node-type" value="command"> Command</label>
    <label><input type="checkbox" name="architecture-node-type" value="extension"> Extension</label>
  </fieldset>

  <p class="architecture-explorer__summary" id="architecture-explorer-summary">Loading graph...</p>

  <div class="architecture-explorer__layout">
    <div class="architecture-explorer__canvas">
      <svg id="architecture-explorer-svg" viewBox="0 0 1600 960" role="img" aria-label="Interactive Foundry architecture graph"></svg>
    </div>
    <aside class="architecture-explorer__details" id="architecture-explorer-details">
      <h2>Node Details</h2>
      <p>Select a node to inspect its metadata, dependencies, dependents, and related docs.</p>
    </aside>
  </div>
</section>

<script id="architecture-graph-data" type="application/json">__ARCHITECTURE_GRAPH_DATA__</script>
<script>
(() => {
  const dataElement = document.getElementById('architecture-graph-data');
  const svg = document.getElementById('architecture-explorer-svg');
  const details = document.getElementById('architecture-explorer-details');
  const summary = document.getElementById('architecture-explorer-summary');
  const searchInput = document.getElementById('architecture-explorer-search');
  const extensionSelect = document.getElementById('architecture-explorer-extension');
  const pipelineSelect = document.getElementById('architecture-explorer-pipeline');
  const typeInputs = Array.from(document.querySelectorAll('input[name="architecture-node-type"]'));
  const svgNamespace = 'http://www.w3.org/2000/svg';
  const layoutOrder = ['feature', 'route', 'command', 'workflow', 'event', 'schema', 'job', 'cache', 'pipeline_stage', 'guard', 'interceptor', 'permission', 'extension', 'other'];
  const pipelineEdgeTypes = new Set([
    'pipeline_stage_next',
    'feature_to_execution_plan',
    'route_to_execution_plan',
    'execution_plan_to_stage',
    'execution_plan_to_guard',
    'execution_plan_to_interceptor',
    'feature_to_guard',
    'guard_to_pipeline_stage',
    'interceptor_to_pipeline_stage',
    'execution_plan_to_feature_action'
  ]);

  if (!dataElement || !svg || !details || !summary || !searchInput || !extensionSelect || !pipelineSelect) {
    return;
  }

  const explorerData = JSON.parse(dataElement.textContent || '{}');
  const rawGraph = isObject(explorerData.graph) ? explorerData.graph : { nodes: [], edges: [] };
  const nodes = Array.isArray(rawGraph.nodes) ? rawGraph.nodes.map(normalizeNode) : [];
  const edges = Array.isArray(rawGraph.edges) ? rawGraph.edges.map(normalizeEdge) : [];
  const nodeById = new Map(nodes.map((node) => [node.id, node]));
  const positions = buildPositions(nodes);
  const bounds = layoutBounds(nodes, positions);
  const stageCache = new Map();

  populateSelect(extensionSelect, Array.isArray(explorerData.extensions) ? explorerData.extensions : [], 'All extensions');
  populateSelect(pipelineSelect, Array.isArray(explorerData.pipeline_stages) ? explorerData.pipeline_stages : [], 'All stages');

  const initialNodeId = initialNodeFromLocation();
  const state = {
    search: '',
    extension: '',
    pipeline: '',
    selectedNodeId: nodeById.has(initialNodeId) ? initialNodeId : null,
  };

  searchInput.addEventListener('input', () => {
    state.search = String(searchInput.value || '');
    render();
  });

  extensionSelect.addEventListener('change', () => {
    state.extension = String(extensionSelect.value || '');
    render();
  });

  pipelineSelect.addEventListener('change', () => {
    state.pipeline = String(pipelineSelect.value || '');
    render();
  });

  typeInputs.forEach((input) => {
    input.addEventListener('change', render);
  });

  details.addEventListener('click', (event) => {
    const button = event.target.closest('[data-node-id]');
    if (!(button instanceof HTMLElement)) {
      return;
    }

    const nodeId = button.getAttribute('data-node-id');
    if (!nodeId || !nodeById.has(nodeId)) {
      return;
    }

    state.selectedNodeId = nodeId;
    syncLocation();
    render();
  });

  render();

  function render() {
    const visibleNodes = nodes.filter(matchesFilters);
    const visibleNodeIds = new Set(visibleNodes.map((node) => node.id));
    const visibleEdges = edges.filter((edge) => visibleNodeIds.has(edge.from) && visibleNodeIds.has(edge.to));

    if (state.selectedNodeId !== null && !visibleNodeIds.has(state.selectedNodeId)) {
      state.selectedNodeId = null;
    }

    const selectedNode = state.selectedNodeId !== null ? nodeById.get(state.selectedNodeId) || null : null;
    const dependencyIds = new Set(selectedNode ? selectedNode.dependencies : []);
    const dependentIds = new Set(selectedNode ? selectedNode.dependents : []);
    const relatedIds = new Set();

    if (selectedNode) {
      relatedIds.add(selectedNode.id);
      dependencyIds.forEach((nodeId) => relatedIds.add(nodeId));
      dependentIds.forEach((nodeId) => relatedIds.add(nodeId));
    }

    const searchQuery = state.search.trim().toLowerCase();
    const matchIds = new Set(
      visibleNodes
        .filter((node) => searchQuery !== '' && node.searchText.includes(searchQuery))
        .map((node) => node.id)
    );

    renderSvg(visibleNodes, visibleEdges, selectedNode, dependencyIds, dependentIds, relatedIds, matchIds);
    renderDetails(selectedNode, dependencyIds, dependentIds);
    renderSummary(visibleNodes.length, visibleEdges.length, matchIds.size, selectedNode);
    syncLocation();
  }

  function renderSvg(visibleNodes, visibleEdges, selectedNode, dependencyIds, dependentIds, relatedIds, matchIds) {
    while (svg.firstChild) {
      svg.removeChild(svg.firstChild);
    }

    svg.setAttribute('viewBox', '0 0 ' + bounds.width + ' ' + bounds.height);

    const defs = document.createElementNS(svgNamespace, 'defs');
    const marker = document.createElementNS(svgNamespace, 'marker');
    marker.setAttribute('id', 'architecture-arrow');
    marker.setAttribute('markerWidth', '10');
    marker.setAttribute('markerHeight', '8');
    marker.setAttribute('refX', '10');
    marker.setAttribute('refY', '4');
    marker.setAttribute('orient', 'auto');
    const arrowPath = document.createElementNS(svgNamespace, 'path');
    arrowPath.setAttribute('d', 'M0,0 L10,4 L0,8 Z');
    arrowPath.setAttribute('fill', '#8b7965');
    marker.appendChild(arrowPath);
    defs.appendChild(marker);
    svg.appendChild(defs);

    if (visibleNodes.length === 0) {
      const empty = document.createElementNS(svgNamespace, 'text');
      empty.setAttribute('x', String(bounds.width / 2));
      empty.setAttribute('y', String(bounds.height / 2));
      empty.setAttribute('text-anchor', 'middle');
      empty.setAttribute('fill', '#5f6b66');
      empty.setAttribute('font-size', '22');
      empty.textContent = 'No nodes match the current filters.';
      svg.appendChild(empty);
      return;
    }

    visibleEdges.forEach((edge) => {
      const from = positions[edge.from];
      const to = positions[edge.to];
      if (!from || !to) {
        return;
      }

      const line = document.createElementNS(svgNamespace, 'line');
      line.setAttribute('x1', String(from.x));
      line.setAttribute('y1', String(from.y));
      line.setAttribute('x2', String(to.x));
      line.setAttribute('y2', String(to.y));
      line.setAttribute('marker-end', 'url(#architecture-arrow)');

      const relatedClass = edgeRelationshipClass(edge, selectedNode, dependencyIds, dependentIds);
      line.setAttribute('class', 'architecture-explorer__edge ' + relatedClass);
      svg.appendChild(line);
    });

    visibleNodes.forEach((node) => {
      const position = positions[node.id];
      if (!position) {
        return;
      }

      const group = document.createElementNS(svgNamespace, 'g');
      group.setAttribute('transform', 'translate(' + position.x + ' ' + position.y + ')');
      group.setAttribute('class', nodeClass(node, selectedNode, relatedIds, matchIds));
      group.setAttribute('tabindex', '0');
      group.setAttribute('role', 'button');
      group.setAttribute('aria-label', node.label + ' (' + node.type + ')');

      group.addEventListener('click', () => {
        state.selectedNodeId = node.id;
        render();
      });

      group.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }

        event.preventDefault();
        state.selectedNodeId = node.id;
        render();
      });

      const box = document.createElementNS(svgNamespace, 'rect');
      box.setAttribute('x', '-78');
      box.setAttribute('y', '-30');
      box.setAttribute('rx', '18');
      box.setAttribute('width', '156');
      box.setAttribute('height', '60');
      box.setAttribute('class', 'architecture-explorer__node-box architecture-explorer__node-box--' + colorBucket(node));
      group.appendChild(box);

      const label = document.createElementNS(svgNamespace, 'text');
      label.setAttribute('class', 'architecture-explorer__node-label');
      label.setAttribute('text-anchor', 'middle');
      label.setAttribute('y', '-4');
      label.textContent = truncate(node.label, 20);
      group.appendChild(label);

      const type = document.createElementNS(svgNamespace, 'text');
      type.setAttribute('class', 'architecture-explorer__node-type');
      type.setAttribute('text-anchor', 'middle');
      type.setAttribute('y', '16');
      type.textContent = node.type;
      group.appendChild(type);

      const title = document.createElementNS(svgNamespace, 'title');
      title.textContent = node.id + ' - ' + node.label + ' (' + node.type + ')';
      group.appendChild(title);

      svg.appendChild(group);
    });
  }

  function renderDetails(selectedNode, dependencyIds, dependentIds) {
    if (!selectedNode) {
      details.innerHTML = '<h2>Node Details</h2><p>Select a node to inspect its metadata, dependencies, dependents, and related docs.</p>';
      return;
    }

    const docsLink = selectedNode.docsHref
      ? '<p><a class="architecture-explorer__docs-link" href="' + escapeHtml(selectedNode.docsHref) + '">Open related docs page</a></p>'
      : '<p class="architecture-explorer__muted">No dedicated docs page is mapped for this node type. Inline metadata remains available below.</p>';

    details.innerHTML = ''
      + '<h2>' + escapeHtml(selectedNode.label) + '</h2>'
      + '<p><strong>ID:</strong> <code>' + escapeHtml(selectedNode.id) + '</code></p>'
      + '<p><strong>Type:</strong> ' + escapeHtml(selectedNode.type) + '</p>'
      + '<p><strong>Extension:</strong> ' + escapeHtml(selectedNode.extension || 'none') + '</p>'
      + '<p><strong>Source:</strong> <code>' + escapeHtml(selectedNode.sourcePath || 'n/a') + '</code></p>'
      + docsLink
      + relationSection('Dependencies', Array.from(dependencyIds))
      + relationSection('Dependents', Array.from(dependentIds))
      + '<h3>Metadata</h3>'
      + '<pre class="architecture-explorer__pre">' + escapeHtml(JSON.stringify(selectedNode.payload, null, 2)) + '</pre>';
  }

  function renderSummary(nodeCount, edgeCount, matchCount, selectedNode) {
    const visibleTypeCounts = {};
    nodes.filter(matchesFilters).forEach((node) => {
      visibleTypeCounts[node.bucket] = (visibleTypeCounts[node.bucket] || 0) + 1;
    });

    const typeSummary = Object.keys(visibleTypeCounts)
      .sort()
      .map((key) => key + '=' + visibleTypeCounts[key])
      .join(', ');

    summary.textContent = 'Showing ' + nodeCount + ' nodes and ' + edgeCount + ' edges.'
      + (matchCount > 0 ? ' Search matches: ' + matchCount + '.' : '')
      + (selectedNode ? ' Selected: ' + selectedNode.label + '.' : '')
      + (typeSummary !== '' ? ' Visible buckets: ' + typeSummary + '.' : '');
  }

  function matchesFilters(node) {
    const checkedTypes = new Set(typeInputs.filter((input) => input.checked).map((input) => input.value));
    if (checkedTypes.size > 0 && !checkedTypes.has(node.bucket)) {
      return false;
    }

    if (state.extension !== '' && node.extension !== state.extension) {
      return false;
    }

    if (state.pipeline !== '') {
      const stageNodeIds = stageRelatedNodeIds(state.pipeline);
      if (!stageNodeIds.has(node.id)) {
        return false;
      }
    }

    return true;
  }

  function stageRelatedNodeIds(stageName) {
    if (stageCache.has(stageName)) {
      return stageCache.get(stageName);
    }

    const stageNode = nodes.find((node) => node.type === 'pipeline_stage' && String(node.payload.name || '') === stageName);
    if (!stageNode) {
      const empty = new Set();
      stageCache.set(stageName, empty);
      return empty;
    }

    const related = new Set([stageNode.id]);
    const queue = [stageNode.id];

    while (queue.length > 0) {
      const nodeId = queue.shift();
      edges.forEach((edge) => {
        if (!pipelineEdgeTypes.has(edge.type)) {
          return;
        }

        if (edge.from !== nodeId && edge.to !== nodeId) {
          return;
        }

        const otherId = edge.from === nodeId ? edge.to : edge.from;
        if (related.has(otherId)) {
          return;
        }

        related.add(otherId);
        queue.push(otherId);
      });
    }

    stageCache.set(stageName, related);
    return related;
  }

  function buildPositions(allNodes) {
    const grouped = new Map();
    allNodes.slice().sort(compareNodes).forEach((node) => {
      const bucket = layoutBucket(node);
      if (!grouped.has(bucket)) {
        grouped.set(bucket, []);
      }
      grouped.get(bucket).push(node);
    });

    const positions = {};
    layoutOrder.forEach((bucket, laneIndex) => {
      const bucketNodes = grouped.get(bucket) || [];
      bucketNodes.forEach((node, index) => {
        positions[node.id] = {
          x: 170 + laneIndex * 220,
          y: 120 + index * 92
        };
      });
    });

    return positions;
  }

  function layoutBounds(allNodes, allPositions) {
    const maxX = allNodes.reduce((carry, node) => Math.max(carry, (allPositions[node.id] || { x: 0 }).x), 0);
    const maxY = allNodes.reduce((carry, node) => Math.max(carry, (allPositions[node.id] || { y: 0 }).y), 0);

    return {
      width: Math.max(1600, maxX + 180),
      height: Math.max(960, maxY + 140)
    };
  }

  function normalizeNode(node) {
    const payload = isObject(node.payload) ? node.payload : {};
    const dependencyMetadata = isObject(node.dependency_metadata) ? node.dependency_metadata : {};
    const dependencies = Array.isArray(dependencyMetadata.dependencies) ? dependencyMetadata.dependencies.map(String) : [];
    const dependents = Array.isArray(dependencyMetadata.dependents) ? dependencyMetadata.dependents.map(String) : [];
    const extension = typeof payload.extension === 'string' && payload.extension.trim() !== '' ? payload.extension.trim() : '';
    const normalized = {
      id: String(node.id || ''),
      type: String(node.type || 'node'),
      payload: payload,
      sourcePath: String(node.source_path || ''),
      dependencies: dependencies,
      dependents: dependents,
      extension: extension,
    };

    normalized.label = labelFor(normalized);
    normalized.bucket = filterBucketFor(normalized);
    normalized.docsHref = docsHrefFor(normalized);
    normalized.searchText = [normalized.id, normalized.type, normalized.label].join(' ').toLowerCase();

    return normalized;
  }

  function normalizeEdge(edge) {
    return {
      id: String(edge.id || ''),
      type: String(edge.type || 'edge'),
      from: String(edge.from || ''),
      to: String(edge.to || ''),
      payload: isObject(edge.payload) ? edge.payload : {}
    };
  }

  function filterBucketFor(node) {
    switch (node.type) {
      case 'feature':
        return 'feature';
      case 'route':
        return 'route';
      case 'execution_plan':
        return 'command';
      case 'workflow':
      case 'orchestration':
        return 'workflow';
      case 'event':
        return 'event';
      case 'schema':
        return 'schema';
      default:
        return node.extension !== '' ? 'extension' : 'other';
    }
  }

  function layoutBucket(node) {
    if (node.type === 'pipeline_stage' || node.type === 'guard' || node.type === 'interceptor') {
      return node.type;
    }
    if (node.type === 'job') {
      return 'job';
    }
    if (node.type === 'cache') {
      return 'cache';
    }
    if (node.type === 'permission') {
      return 'permission';
    }

    return filterBucketFor(node);
  }

  function colorBucket(node) {
    const bucket = layoutBucket(node);
    return layoutOrder.includes(bucket) ? bucket : 'other';
  }

  function labelFor(node) {
    const payload = node.payload || {};

    switch (node.type) {
      case 'feature':
        return String(payload.feature || node.id);
      case 'route':
        return String(payload.signature || node.id);
      case 'schema':
        return String(payload.path || node.id);
      case 'event':
      case 'job':
      case 'permission':
        return String(payload.name || node.id);
      case 'cache':
        return String(payload.key || node.id);
      case 'workflow':
        return String(payload.resource || node.id);
      case 'orchestration':
        return String(payload.name || node.id);
      case 'pipeline_stage':
        return String(payload.name || node.id);
      case 'guard':
        return String(payload.type || node.id);
      case 'interceptor':
        return String(payload.id || node.id);
      case 'execution_plan':
        return String(payload.route_signature || payload.feature || node.id);
      default:
        return node.id;
    }
  }

  function docsHrefFor(node) {
    switch (node.type) {
      case 'feature':
        return 'features.html#' + headingId(node.payload.feature || node.id);
      case 'route':
        return 'routes.html#' + headingId(node.payload.signature || node.id);
      case 'event':
        return 'events.html#' + headingId(node.payload.name || node.id);
      case 'job':
        return 'jobs.html#' + headingId(node.payload.name || node.id);
      case 'cache':
        return 'caches.html#' + headingId(node.payload.key || node.id);
      case 'schema':
        return 'schemas.html';
      case 'permission':
        return 'auth.html';
      default:
        return null;
    }
  }

  function relationSection(title, nodeIds) {
    const items = nodeIds
      .map((nodeId) => nodeById.get(nodeId))
      .filter(Boolean)
      .sort(compareNodes)
      .map((node) => '<li><button type="button" data-node-id="' + escapeHtml(node.id) + '">' + escapeHtml(node.label) + '</button></li>')
      .join('');

    if (items === '') {
      return '<h3>' + escapeHtml(title) + '</h3><p class="architecture-explorer__muted">None</p>';
    }

    return '<h3>' + escapeHtml(title) + '</h3><ul class="architecture-explorer__relations">' + items + '</ul>';
  }

  function nodeClass(node, selectedNode, relatedIds, matchIds) {
    const classes = ['architecture-explorer__node'];
    if (selectedNode) {
      if (node.id === selectedNode.id) {
        classes.push('is-selected');
      } else if (relatedIds.has(node.id)) {
        classes.push('is-related');
      } else {
        classes.push('is-muted');
      }
    }

    if (matchIds.has(node.id)) {
      classes.push('is-match');
    }

    return classes.join(' ');
  }

  function edgeRelationshipClass(edge, selectedNode, dependencyIds, dependentIds) {
    if (!selectedNode) {
      return 'architecture-explorer__edge--default';
    }

    if (edge.from === selectedNode.id && dependencyIds.has(edge.to)) {
      return 'architecture-explorer__edge--dependency';
    }

    if (edge.to === selectedNode.id && dependentIds.has(edge.from)) {
      return 'architecture-explorer__edge--dependent';
    }

    return 'architecture-explorer__edge--muted';
  }

  function headingId(value) {
    const slug = String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return slug !== '' ? slug : 'section';
  }

  function compareNodes(left, right) {
    const leftBucket = layoutBucket(left);
    const rightBucket = layoutBucket(right);
    if (leftBucket !== rightBucket) {
      return layoutOrder.indexOf(leftBucket) - layoutOrder.indexOf(rightBucket);
    }

    return left.label.localeCompare(right.label);
  }

  function populateSelect(select, values, emptyLabel) {
    const uniqueValues = Array.from(new Set(values.map(String).filter((value) => value !== ''))).sort();
    select.innerHTML = '<option value="">' + escapeHtml(emptyLabel) + '</option>'
      + uniqueValues.map((value) => '<option value="' + escapeHtml(value) + '">' + escapeHtml(value) + '</option>').join('');
  }

  function initialNodeFromLocation() {
    const url = new URL(window.location.href);
    const searchValue = url.searchParams.get('node');
    if (searchValue) {
      return searchValue;
    }

    const hash = window.location.hash.replace(/^#/, '');
    if (hash.startsWith('node=')) {
      return decodeURIComponent(hash.substring(5));
    }

    return '';
  }

  function syncLocation() {
    const url = new URL(window.location.href);
    if (state.selectedNodeId) {
      url.searchParams.set('node', state.selectedNodeId);
      url.hash = 'node=' + encodeURIComponent(state.selectedNodeId);
    } else {
      url.searchParams.delete('node');
      url.hash = '';
    }

    window.history.replaceState({}, '', url.toString());
  }

  function truncate(value, limit) {
    return value.length > limit ? value.substring(0, limit - 3) + '...' : value;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
  }
})();
</script>

<style>
.architecture-explorer {
  display: grid;
  gap: 20px;
}

.architecture-explorer__header h1 {
  margin: 0 0 12px;
}

.architecture-explorer__header p {
  margin: 0;
  color: #5f6b66;
}

.architecture-explorer__toolbar {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.architecture-explorer__field {
  display: grid;
  gap: 8px;
  font-weight: 600;
}

.architecture-explorer__field input,
.architecture-explorer__field select {
  width: 100%;
  border: 1px solid rgba(31, 42, 38, 0.16);
  border-radius: 12px;
  background: rgba(255, 252, 247, 0.96);
  color: #1f2a26;
  font: inherit;
  padding: 10px 12px;
}

.architecture-explorer__types {
  border: 1px solid rgba(31, 42, 38, 0.14);
  border-radius: 16px;
  background: rgba(255, 252, 247, 0.76);
  display: flex;
  flex-wrap: wrap;
  gap: 12px 18px;
  padding: 14px 16px 16px;
}

.architecture-explorer__types legend {
  font-weight: 700;
  padding: 0 8px;
}

.architecture-explorer__types label {
  align-items: center;
  display: inline-flex;
  gap: 8px;
}

.architecture-explorer__summary {
  margin: 0;
  color: #5f6b66;
  font-size: 0.96rem;
}

.architecture-explorer__layout {
  display: grid;
  gap: 18px;
  grid-template-columns: minmax(0, 2.15fr) minmax(280px, 1fr);
}

.architecture-explorer__canvas,
.architecture-explorer__details {
  background: rgba(255, 252, 247, 0.9);
  border: 1px solid rgba(31, 42, 38, 0.14);
  border-radius: 22px;
  box-shadow: var(--shadow);
}

.architecture-explorer__canvas {
  overflow: auto;
  padding: 10px;
}

.architecture-explorer__canvas svg {
  display: block;
  min-height: 680px;
  width: 100%;
}

.architecture-explorer__details {
  align-content: start;
  display: grid;
  gap: 10px;
  padding: 20px;
}

.architecture-explorer__details h2,
.architecture-explorer__details h3,
.architecture-explorer__details p {
  margin: 0;
}

.architecture-explorer__docs-link {
  font-weight: 700;
}

.architecture-explorer__muted {
  color: #7a6757;
}

.architecture-explorer__relations {
  display: grid;
  gap: 8px;
  list-style: none;
  margin: 0;
  padding: 0;
}

.architecture-explorer__relations button {
  width: 100%;
  border: 1px solid rgba(31, 42, 38, 0.14);
  border-radius: 12px;
  background: #fffdf9;
  color: #1f2a26;
  cursor: pointer;
  font: inherit;
  padding: 9px 10px;
  text-align: left;
}

.architecture-explorer__relations button:hover {
  border-color: rgba(135, 92, 47, 0.45);
}

.architecture-explorer__pre {
  background: #f7f1e7;
  border: 1px solid rgba(31, 42, 38, 0.1);
  border-radius: 14px;
  margin: 0;
  max-height: 320px;
  overflow: auto;
  padding: 12px;
}

.architecture-explorer__edge {
  fill: none;
  stroke-linecap: round;
  stroke-width: 2.2;
}

.architecture-explorer__edge--default {
  opacity: 0.58;
  stroke: #8b7965;
}

.architecture-explorer__edge--dependency {
  opacity: 0.95;
  stroke: #2c7a67;
}

.architecture-explorer__edge--dependent {
  opacity: 0.95;
  stroke: #b85d2d;
}

.architecture-explorer__edge--muted {
  opacity: 0.16;
  stroke: #b8aea2;
}

.architecture-explorer__node {
  cursor: pointer;
  transition: opacity 140ms ease, transform 140ms ease;
}

.architecture-explorer__node:focus-visible {
  outline: none;
}

.architecture-explorer__node.is-muted {
  opacity: 0.24;
}

.architecture-explorer__node.is-related {
  opacity: 0.96;
}

.architecture-explorer__node.is-selected {
  opacity: 1;
}

.architecture-explorer__node.is-match .architecture-explorer__node-box {
  stroke: #d4a12a;
  stroke-width: 3.2;
}

.architecture-explorer__node.is-selected .architecture-explorer__node-box {
  stroke: #1f2a26;
  stroke-width: 3.6;
}

.architecture-explorer__node-box {
  fill: #fff7ea;
  stroke: rgba(31, 42, 38, 0.18);
  stroke-width: 1.8;
}

.architecture-explorer__node-box--feature { fill: #f7efe2; }
.architecture-explorer__node-box--route { fill: #efe7f6; }
.architecture-explorer__node-box--command { fill: #e9f0fb; }
.architecture-explorer__node-box--workflow { fill: #e7f6ed; }
.architecture-explorer__node-box--event { fill: #fff1db; }
.architecture-explorer__node-box--schema { fill: #f5e8ea; }
.architecture-explorer__node-box--job { fill: #e8f4f0; }
.architecture-explorer__node-box--cache { fill: #f7f2dc; }
.architecture-explorer__node-box--pipeline_stage { fill: #e6eef7; }
.architecture-explorer__node-box--guard { fill: #f5e7d9; }
.architecture-explorer__node-box--interceptor { fill: #eee6f7; }
.architecture-explorer__node-box--permission { fill: #f5eadb; }
.architecture-explorer__node-box--extension { fill: #edf4e6; }
.architecture-explorer__node-box--other { fill: #f3eee8; }

.architecture-explorer__node-label {
  fill: #1f2a26;
  font-size: 13px;
  font-weight: 700;
}

.architecture-explorer__node-type {
  fill: #6d6a63;
  font-size: 11px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

@media (max-width: 960px) {
  .architecture-explorer__layout {
    grid-template-columns: 1fr;
  }

  .architecture-explorer__canvas svg {
    min-height: 540px;
  }
}
</style>
HTML;

        return str_replace('__ARCHITECTURE_GRAPH_DATA__', $dataJson, $template);
    }

    /**
     * @return array<string,mixed>
     */
    private function architectureExplorerData(ApplicationGraph $graph): array
    {
        return [
            'schema_version' => 1,
            'graph' => $graph->toArray(new DiagnosticBag()),
            'extensions' => $this->architectureExplorerExtensions($graph),
            'pipeline_stages' => $this->architectureExplorerPipelineStages($graph),
            'type_filters' => ['feature', 'route', 'workflow', 'event', 'schema', 'command', 'extension'],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function architectureExplorerExtensions(ApplicationGraph $graph): array
    {
        $extensions = [];

        foreach ($graph->nodes() as $node) {
            $extension = trim((string) ($node->payload()['extension'] ?? ''));
            if ($extension !== '') {
                $extensions[$extension] = true;
            }
        }

        foreach (ExtensionRegistry::forPaths($this->paths)->inspectRows() as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $extensions[$name] = true;
            }
        }

        ksort($extensions);

        return array_keys($extensions);
    }

    /**
     * @return array<int,string>
     */
    private function architectureExplorerPipelineStages(ApplicationGraph $graph): array
    {
        $stages = [];
        foreach ($graph->nodesByType('pipeline_stage') as $node) {
            $stage = trim((string) ($node->payload()['name'] ?? ''));
            if ($stage !== '') {
                $stages[$stage] = true;
            }
        }

        ksort($stages);

        return array_keys($stages);
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

        uksort($versions, fn(string $a, string $b): int => $this->compareVersions($a, $b));

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
            'mode' => self::BUILD_MODE,
            'deprecation' => $this->legacyPreviewMetadata(),
            'version' => $siteVersion,
            'current_version' => $currentVersion,
            'pages' => $pageRows,
            'sections' => $sections,
        ];
        file_put_contents($outputRoot . '/manifest.json', Json::encode($manifest, true) . "\n");

        return [
            'mode' => self::BUILD_MODE,
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
        $previewNotice = $this->renderPreviewNotice();

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
    {$previewNotice}
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
        $previewNotice = $this->renderPreviewNotice();

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
    {$previewNotice}
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
            static fn(array $a, array $b): int => ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0))
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
            ['slug' => 'architecture-explorer', 'title' => 'Architecture Explorer', 'section' => 'Architecture', 'source' => 'generated_html:architecture-explorer'],
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
            static fn(string $version): bool => $version !== '',
        )));
        usort($snapshotVersions, fn(string $a, string $b): int => $this->compareVersions($a, $b));

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

    /**
     * @return array<string,string>
     */
    private function legacyPreviewMetadata(): array
    {
        return [
            'status' => 'deprecated',
            'mode' => self::BUILD_MODE,
            'message' => self::PREVIEW_NOTICE,
            'authoritative_source' => 'framework/docs',
            'authoritative_publisher' => 'website_repo',
            'snapshot_notice' => self::SNAPSHOT_NOTICE,
        ];
    }

    private function renderPreviewNotice(): string
    {
        return '<p class="preview-notice">' . htmlspecialchars(self::PREVIEW_NOTICE, ENT_QUOTES) . '</p>';
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

.preview-notice {
  margin: 0 0 18px;
  padding: 12px 14px;
  border: 1px solid rgba(135, 92, 47, 0.26);
  background: rgba(255, 247, 235, 0.92);
  color: #5c3d1f;
  border-radius: 14px;
  box-shadow: var(--shadow);
  font-size: 0.98rem;
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
