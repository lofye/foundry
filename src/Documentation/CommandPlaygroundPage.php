<?php

declare(strict_types=1);

namespace Foundry\Documentation;

use Foundry\Compiler\ApplicationGraph;
use Foundry\Support\ApiSurfaceRegistry;
use Foundry\Support\Json;
use Foundry\Support\Paths;

final class CommandPlaygroundPage
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ApiSurfaceRegistry $apiSurfaceRegistry,
    ) {}

    public function content(ApplicationGraph $graph): string
    {
        $catalog = new CommandCatalog($this->paths, $this->apiSurfaceRegistry);
        $dataJson = htmlspecialchars(Json::encode($catalog->data($graph), true), ENT_NOQUOTES);

        $template = <<<'HTML'
<section class="command-playground" id="command-playground">
  <div class="command-playground__header">
    <h1>Command Playground</h1>
    <p>Static, browser-based CLI reference driven by the Foundry command registry and deterministic preview data. Commands are never executed here.</p>
  </div>

  <div class="command-playground__toolbar">
    <label class="command-playground__field">
      <span>Search</span>
      <input id="command-playground-search" type="search" placeholder="Search commands, descriptions, or usage">
    </label>
    <label class="command-playground__field">
      <span>Stability</span>
      <select id="command-playground-stability">
        <option value="">All stability levels</option>
        <option value="stable">Stable</option>
        <option value="experimental">Experimental</option>
        <option value="internal">Internal</option>
      </select>
    </label>
  </div>

  <p class="command-playground__summary" id="command-playground-summary">Loading commands...</p>

  <div class="command-playground__layout">
    <aside class="command-playground__list-panel">
      <div id="command-playground-list"></div>
    </aside>
    <article class="command-playground__details" id="command-playground-details">
      <h2>Command Details</h2>
      <p>Select a command to inspect its usage, deterministic JSON preview, related docs, explain targets, and graph links.</p>
    </article>
  </div>
</section>

<script id="command-playground-data" type="application/json">__COMMAND_PLAYGROUND_DATA__</script>
<script>
(() => {
  const dataElement = document.getElementById('command-playground-data');
  const listElement = document.getElementById('command-playground-list');
  const detailsElement = document.getElementById('command-playground-details');
  const summaryElement = document.getElementById('command-playground-summary');
  const searchInput = document.getElementById('command-playground-search');
  const stabilitySelect = document.getElementById('command-playground-stability');

  if (!dataElement || !listElement || !detailsElement || !summaryElement || !searchInput || !stabilitySelect) {
    return;
  }

  const payload = JSON.parse(dataElement.textContent || '{}');
  const commands = Array.isArray(payload.commands) ? payload.commands : [];
  const commandBySignature = new Map(commands.map((command) => [command.signature, command]));
  const initialSignature = initialCommandFromLocation();

  const state = {
    search: '',
    stability: '',
    selectedSignature: commandBySignature.has(initialSignature)
      ? initialSignature
      : (commands[0] ? String(commands[0].signature || '') : null)
  };

  searchInput.addEventListener('input', () => {
    state.search = String(searchInput.value || '').trim().toLowerCase();
    render();
  });

  stabilitySelect.addEventListener('change', () => {
    state.stability = String(stabilitySelect.value || '');
    render();
  });

  listElement.addEventListener('click', (event) => {
    const button = event.target.closest('[data-command-signature]');
    if (!(button instanceof HTMLElement)) {
      return;
    }

    const signature = button.getAttribute('data-command-signature');
    if (!signature || !commandBySignature.has(signature)) {
      return;
    }

    state.selectedSignature = signature;
    render();
  });

  detailsElement.addEventListener('click', (event) => {
    const button = event.target.closest('[data-related-command]');
    if (!(button instanceof HTMLElement)) {
      return;
    }

    const signature = button.getAttribute('data-related-command');
    if (!signature || !commandBySignature.has(signature)) {
      return;
    }

    state.selectedSignature = signature;
    render();
  });

  render();

  function render() {
    const filteredCommands = commands.filter(matchesFilters);

    if (state.selectedSignature !== null && !filteredCommands.some((command) => command.signature === state.selectedSignature)) {
      state.selectedSignature = filteredCommands[0] ? String(filteredCommands[0].signature || '') : null;
    }

    const selectedCommand = state.selectedSignature !== null
      ? commandBySignature.get(state.selectedSignature) || null
      : null;

    renderList(filteredCommands, selectedCommand);
    renderDetails(selectedCommand);
    renderSummary(filteredCommands.length, selectedCommand);
    syncLocation();
  }

  function renderList(filteredCommands, selectedCommand) {
    if (filteredCommands.length === 0) {
      listElement.innerHTML = '<p class="command-playground__empty">No commands match the current filters.</p>';
      return;
    }

    const groups = {
      stable: [],
      experimental: [],
      internal: []
    };

    filteredCommands.forEach((command) => {
      const stability = String(command.stability || 'internal');
      groups[stability] = Array.isArray(groups[stability]) ? groups[stability] : [];
      groups[stability].push(command);
    });

    const sections = [
      renderGroup('Stable Commands', groups.stable || [], selectedCommand),
      renderGroup('Experimental Commands', groups.experimental || [], selectedCommand),
      renderGroup('Internal Commands', groups.internal || [], selectedCommand)
    ].filter(Boolean);

    listElement.innerHTML = sections.join('');
  }

  function renderGroup(title, entries, selectedCommand) {
    if (!Array.isArray(entries) || entries.length === 0) {
      return '';
    }

    const items = entries.map((command) => {
      const isSelected = selectedCommand && selectedCommand.signature === command.signature;
      const selectedClass = isSelected ? ' is-selected' : '';

      return ''
        + '<button type="button" class="command-playground__list-item' + selectedClass + '" data-command-signature="' + escapeHtml(command.signature) + '">'
        + '<span class="command-playground__list-signature">' + escapeHtml(command.signature) + '</span>'
        + '<span class="command-playground__list-summary">' + escapeHtml(command.description || '') + '</span>'
        + '<code class="command-playground__list-usage">' + escapeHtml(command.usage || '') + '</code>'
        + '</button>';
    }).join('');

    return ''
      + '<section class="command-playground__group">'
      + '<h2>' + escapeHtml(title) + '</h2>'
      + items
      + '</section>';
  }

  function renderDetails(command) {
    if (!command) {
      detailsElement.innerHTML = '<h2>Command Details</h2><p>Select a command to inspect its usage, deterministic JSON preview, related docs, explain targets, and graph links.</p>';
      return;
    }

    const docs = renderLinkList(command.docs, 'No related docs linked.');
    const relatedNodes = renderLinkList(command.relatedNodes, 'No related graph nodes are attached to this command preview.');
    const relatedCommands = renderRelatedCommands(command.relatedCommands);
    const explainTargets = renderExplainTargets(command.explainTargets);
    const examples = Array.isArray(command.examples) && command.examples.length > 0
      ? '<ul class="command-playground__code-list">' + command.examples.map((example) => '<li><code>' + escapeHtml(example) + '</code></li>').join('') + '</ul>'
      : '<p class="command-playground__muted">No examples published.</p>';

    detailsElement.innerHTML = ''
      + '<h2>' + escapeHtml(command.signature) + '</h2>'
      + '<p class="command-playground__meta">'
      + '<span>' + escapeHtml(command.stability || 'internal') + '</span>'
      + '<span>' + escapeHtml(command.availability || 'core') + '</span>'
      + '</p>'
      + '<p>' + escapeHtml(command.description || '') + '</p>'
      + '<h3>Usage Signature</h3>'
      + '<pre class="command-playground__pre"><code>' + escapeHtml(command.usage || '') + '</code></pre>'
      + '<h3>Usage Examples</h3>'
      + examples
      + '<h3>Sample JSON Output</h3>'
      + '<p class="command-playground__muted">' + escapeHtml(command.sampleOutputLabel || 'Sample JSON output') + '</p>'
      + '<pre class="command-playground__pre"><code>' + escapeHtml(JSON.stringify(command.sampleOutput || {}, null, 2)) + '</code></pre>'
      + '<h3>Related Documentation</h3>'
      + docs
      + '<h3>Related Explain Targets</h3>'
      + explainTargets
      + '<h3>Related Commands</h3>'
      + relatedCommands
      + '<h3>Related Graph Nodes</h3>'
      + relatedNodes;
  }

  function renderSummary(count, selectedCommand) {
    const commandLabel = selectedCommand ? ' Selected: ' + selectedCommand.signature + '.' : '';
    summaryElement.textContent = 'Showing ' + count + ' commands.' + commandLabel;
  }

  function renderLinkList(items, emptyMessage) {
    if (!Array.isArray(items) || items.length === 0) {
      return '<p class="command-playground__muted">' + escapeHtml(emptyMessage) + '</p>';
    }

    return '<ul class="command-playground__link-list">' + items.map((item) => {
      const title = escapeHtml(item.title || item.label || '');
      const meta = item.meta ? ' <span class="command-playground__muted">(' + escapeHtml(item.meta) + ')</span>' : '';

      return '<li><a href="' + escapeHtml(item.href || '#') + '">' + title + '</a>' + meta + '</li>';
    }).join('') + '</ul>';
  }

  function renderRelatedCommands(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return '<p class="command-playground__muted">No closely related commands were published for this command.</p>';
    }

    return '<div class="command-playground__related-buttons">' + items.map((item) => {
      return '<button type="button" data-related-command="' + escapeHtml(item.signature || '') + '">' + escapeHtml(item.signature || '') + '</button>';
    }).join('') + '</div>';
  }

  function renderExplainTargets(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return '<p class="command-playground__muted">No explain targets are attached to this command preview.</p>';
    }

    return '<ul class="command-playground__code-list">' + items.map((item) => {
      const title = escapeHtml(item.title || item.target || '');
      const meta = item.meta ? '<span class="command-playground__muted"> ' + escapeHtml(item.meta) + '</span>' : '';
      const link = item.href ? '<a href="' + escapeHtml(item.href) + '"><code>' + title + '</code></a>' : '<code>' + title + '</code>';

      return '<li>' + link + meta + '</li>';
    }).join('') + '</ul>';
  }

  function matchesFilters(command) {
    if (state.stability !== '' && String(command.stability || '') !== state.stability) {
      return false;
    }

    if (state.search === '') {
      return true;
    }

    return String(command.searchText || '').includes(state.search);
  }

  function initialCommandFromLocation() {
    const params = new URLSearchParams(window.location.search);
    const fromQuery = params.get('command');
    if (fromQuery) {
      return fromQuery;
    }

    if (window.location.hash.startsWith('#command=')) {
      return decodeURIComponent(window.location.hash.slice('#command='.length));
    }

    return '';
  }

  function syncLocation() {
    if (!state.selectedSignature) {
      return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('command', state.selectedSignature);
    url.hash = 'command=' + encodeURIComponent(state.selectedSignature);
    window.history.replaceState({}, '', url);
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
})();
</script>

<style>
.command-playground {
  display: grid;
  gap: 1.5rem;
}

.command-playground__header h1 {
  margin-bottom: 0.35rem;
}

.command-playground__header p {
  margin: 0;
  color: #5b645f;
}

.command-playground__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.command-playground__field {
  display: grid;
  gap: 0.35rem;
  min-width: 16rem;
}

.command-playground__field input,
.command-playground__field select {
  border: 1px solid #cabaa8;
  border-radius: 0.8rem;
  background: #fffdfa;
  padding: 0.7rem 0.9rem;
  font: inherit;
}

.command-playground__summary {
  margin: 0;
  color: #5b645f;
  font-size: 0.95rem;
}

.command-playground__layout {
  display: grid;
  grid-template-columns: minmax(20rem, 26rem) minmax(0, 1fr);
  gap: 1.25rem;
}

.command-playground__list-panel,
.command-playground__details {
  border: 1px solid #d9ccbc;
  border-radius: 1rem;
  background: #fffdfa;
  box-shadow: 0 14px 40px rgba(68, 49, 27, 0.07);
}

.command-playground__list-panel {
  max-height: 70rem;
  overflow: auto;
  padding: 1rem;
}

.command-playground__details {
  padding: 1.25rem;
}

.command-playground__group + .command-playground__group {
  margin-top: 1.25rem;
}

.command-playground__group h2,
.command-playground__details h2,
.command-playground__details h3 {
  margin-top: 0;
}

.command-playground__list-item {
  display: grid;
  gap: 0.35rem;
  width: 100%;
  margin-top: 0.75rem;
  border: 1px solid #e3d8cb;
  border-radius: 0.9rem;
  background: #fff;
  padding: 0.85rem;
  text-align: left;
  cursor: pointer;
}

.command-playground__list-item:hover {
  border-color: #9d6f34;
}

.command-playground__list-item.is-selected {
  border-color: #9d6f34;
  background: #fbf0e2;
}

.command-playground__list-signature {
  font-weight: 700;
  color: #402c16;
}

.command-playground__list-summary {
  color: #5b645f;
  font-size: 0.95rem;
}

.command-playground__list-usage {
  font-size: 0.83rem;
  color: #3f4f6b;
  white-space: normal;
}

.command-playground__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin: 0 0 0.75rem;
}

.command-playground__meta span {
  border-radius: 999px;
  background: #f3eadf;
  color: #563a16;
  padding: 0.25rem 0.7rem;
  font-size: 0.85rem;
}

.command-playground__muted {
  color: #5b645f;
}

.command-playground__pre {
  overflow: auto;
  border-radius: 0.85rem;
  background: #f7f1ea;
  padding: 0.9rem 1rem;
}

.command-playground__link-list,
.command-playground__code-list {
  padding-left: 1.1rem;
}

.command-playground__related-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
}

.command-playground__related-buttons button {
  border: 1px solid #d2c2b3;
  border-radius: 999px;
  background: #fff;
  padding: 0.45rem 0.8rem;
  font: inherit;
  cursor: pointer;
}

.command-playground__related-buttons button:hover {
  border-color: #9d6f34;
  background: #fbf0e2;
}

.command-playground__empty {
  margin: 0;
  color: #5b645f;
}

@media (max-width: 960px) {
  .command-playground__layout {
    grid-template-columns: 1fr;
  }

  .command-playground__list-panel {
    max-height: none;
  }
}
</style>
HTML;

        return str_replace('__COMMAND_PLAYGROUND_DATA__', $dataJson, $template);
    }
}
