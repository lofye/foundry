<?php

declare(strict_types=1);

namespace Foundry\Documentation;

use Foundry\Support\Json;

final class LearningPathsPage
{
    public function content(): string
    {
        $dataJson = htmlspecialchars(Json::encode($this->data(), true), ENT_NOQUOTES);

        $template = <<<'HTML'
<section class="learning-paths" id="learning-paths">
  <div class="learning-paths__header">
    <h1>Guided Learning Paths</h1>
    <p>Curated, fixed learning sequences that reuse the existing Foundry docs. Pick a path, move step by step, or jump anywhere in the sequence.</p>
  </div>

  <div class="learning-paths__paths" id="learning-paths-list"></div>

  <div class="learning-paths__workspace">
    <div class="learning-paths__progress">
      <div class="learning-paths__progress-bar">
        <span id="learning-paths-progress-bar"></span>
      </div>
      <p id="learning-paths-progress-text">Loading path...</p>
    </div>

    <div class="learning-paths__layout">
      <aside class="learning-paths__steps" id="learning-paths-steps"></aside>
      <article class="learning-paths__details" id="learning-paths-details">
        <h2>Path Details</h2>
        <p>Select a learning path to see the step sequence, related CLI concepts, and explain targets.</p>
      </article>
    </div>
  </div>
</section>

<script id="learning-paths-data" type="application/json">__LEARNING_PATHS_DATA__</script>
<script>
(() => {
  const dataElement = document.getElementById('learning-paths-data');
  const pathListElement = document.getElementById('learning-paths-list');
  const stepsElement = document.getElementById('learning-paths-steps');
  const detailsElement = document.getElementById('learning-paths-details');
  const progressBar = document.getElementById('learning-paths-progress-bar');
  const progressText = document.getElementById('learning-paths-progress-text');

  if (!dataElement || !pathListElement || !stepsElement || !detailsElement || !progressBar || !progressText) {
    return;
  }

  const payload = JSON.parse(dataElement.textContent || '{}');
  const paths = Array.isArray(payload.paths) ? payload.paths : [];
  const pathById = new Map(paths.map((path) => [path.id, path]));
  const initialState = initialSelection();

  const state = {
    pathId: pathById.has(initialState.pathId)
      ? initialState.pathId
      : (paths[0] ? String(paths[0].id || '') : null),
    stepIndex: initialState.stepIndex
  };

  pathListElement.addEventListener('click', (event) => {
    const button = event.target.closest('[data-path-id]');
    if (!(button instanceof HTMLElement)) {
      return;
    }

    const pathId = button.getAttribute('data-path-id');
    if (!pathId || !pathById.has(pathId)) {
      return;
    }

    state.pathId = pathId;
    state.stepIndex = 0;
    render();
  });

  stepsElement.addEventListener('click', (event) => {
    const button = event.target.closest('[data-step-index]');
    if (!(button instanceof HTMLElement)) {
      return;
    }

    const nextIndex = Number(button.getAttribute('data-step-index'));
    if (!Number.isInteger(nextIndex)) {
      return;
    }

    state.stepIndex = nextIndex;
    render();
  });

  detailsElement.addEventListener('click', (event) => {
    const action = event.target.closest('[data-learning-action]');
    if (!(action instanceof HTMLElement)) {
      return;
    }

    const currentPath = state.pathId !== null ? pathById.get(state.pathId) || null : null;
    const steps = currentPath && Array.isArray(currentPath.steps) ? currentPath.steps : [];
    if (steps.length === 0) {
      return;
    }

    const direction = action.getAttribute('data-learning-action');
    if (direction === 'previous') {
      state.stepIndex = Math.max(0, state.stepIndex - 1);
    } else if (direction === 'next') {
      state.stepIndex = Math.min(steps.length - 1, state.stepIndex + 1);
    }

    render();
  });

  render();

  function render() {
    const path = state.pathId !== null ? pathById.get(state.pathId) || null : null;
    if (!path) {
      pathListElement.innerHTML = '<p class="learning-paths__empty">No learning paths were published.</p>';
      stepsElement.innerHTML = '';
      detailsElement.innerHTML = '<h2>Path Details</h2><p>No learning paths were published.</p>';
      progressBar.style.width = '0%';
      progressText.textContent = 'No path selected.';
      return;
    }

    const steps = Array.isArray(path.steps) ? path.steps : [];
    const safeIndex = Math.min(Math.max(state.stepIndex, 0), Math.max(steps.length - 1, 0));
    state.stepIndex = safeIndex;
    const step = steps[safeIndex] || null;

    renderPathCards(path);
    renderSteps(path, steps, safeIndex);
    renderDetails(path, step, safeIndex, steps.length);
    renderProgress(path, safeIndex, steps.length);
    syncLocation();
  }

  function renderPathCards(activePath) {
    pathListElement.innerHTML = paths.map((path) => {
      const activeClass = path.id === activePath.id ? ' is-active' : '';

      return ''
        + '<button type="button" class="learning-paths__path-card' + activeClass + '" data-path-id="' + escapeHtml(path.id || '') + '">'
        + '<span class="learning-paths__path-title">' + escapeHtml(path.title || '') + '</span>'
        + '<span class="learning-paths__path-description">' + escapeHtml(path.description || '') + '</span>'
        + '<span class="learning-paths__path-time">' + escapeHtml(path.estimatedTime || '') + '</span>'
        + '</button>';
    }).join('');
  }

  function renderSteps(path, steps, activeIndex) {
    if (steps.length === 0) {
      stepsElement.innerHTML = '<p class="learning-paths__empty">This path has no published steps.</p>';
      return;
    }

    stepsElement.innerHTML = ''
      + '<h2>' + escapeHtml(path.title || '') + '</h2>'
      + '<p class="learning-paths__muted">' + escapeHtml(path.description || '') + '</p>'
      + '<ol class="learning-paths__step-list">'
      + steps.map((step, index) => {
        const activeClass = index === activeIndex ? ' is-active' : '';
        return ''
          + '<li>'
          + '<button type="button" class="learning-paths__step-button' + activeClass + '" data-step-index="' + index + '">'
          + '<span class="learning-paths__step-count">Step ' + (index + 1) + '</span>'
          + '<span class="learning-paths__step-title">' + escapeHtml(step.title || '') + '</span>'
          + '</button>'
          + '</li>';
      }).join('')
      + '</ol>';
  }

  function renderDetails(path, step, stepIndex, totalSteps) {
    if (!step) {
      detailsElement.innerHTML = '<h2>Path Details</h2><p>Select a learning path to see the step sequence, related CLI concepts, and explain targets.</p>';
      return;
    }

    const cliConcept = renderSupplement(step.cliConcept, 'CLI concept');
    const explainTarget = renderSupplement(step.explainTarget, 'Explain target');
    const previousDisabled = stepIndex === 0 ? ' disabled' : '';
    const nextDisabled = stepIndex >= totalSteps - 1 ? ' disabled' : '';

    detailsElement.innerHTML = ''
      + '<p class="learning-paths__eyebrow">' + escapeHtml(path.title || '') + ' - Step ' + (stepIndex + 1) + ' of ' + totalSteps + '</p>'
      + '<h2>' + escapeHtml(step.title || '') + '</h2>'
      + '<p>' + escapeHtml(step.context || '') + '</p>'
      + '<p><a class="learning-paths__primary-link" href="' + escapeHtml(step.href || '#') + '">Open ' + escapeHtml(step.linkTitle || 'this docs page') + '</a></p>'
      + cliConcept
      + explainTarget
      + '<div class="learning-paths__nav">'
      + '<button type="button" data-learning-action="previous"' + previousDisabled + '>Previous Step</button>'
      + '<button type="button" data-learning-action="next"' + nextDisabled + '>Next Step</button>'
      + '</div>';
  }

  function renderProgress(path, stepIndex, totalSteps) {
    const percent = totalSteps > 0 ? ((stepIndex + 1) / totalSteps) * 100 : 0;
    progressBar.style.width = percent + '%';
    progressText.textContent = (path.estimatedTime || '') + ' - Step ' + (stepIndex + 1) + ' of ' + totalSteps;
  }

  function renderSupplement(item, label) {
    if (!item || typeof item !== 'object') {
      return '';
    }

    const title = escapeHtml(item.title || '');
    const href = escapeHtml(item.href || '#');
    const context = item.context ? '<span class="learning-paths__muted"> ' + escapeHtml(item.context) + '</span>' : '';

    return ''
      + '<p><strong>' + escapeHtml(label) + ':</strong> <a href="' + href + '"><code>' + title + '</code></a>' + context + '</p>';
  }

  function initialSelection() {
    const params = new URLSearchParams(window.location.search);
    const pathId = params.get('path') || '';
    const stepIndex = Number(params.get('step') || '0');

    return {
      pathId,
      stepIndex: Number.isInteger(stepIndex) && stepIndex >= 0 ? stepIndex : 0
    };
  }

  function syncLocation() {
    if (!state.pathId) {
      return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('path', state.pathId);
    url.searchParams.set('step', String(state.stepIndex));
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
.learning-paths {
  display: grid;
  gap: 1.5rem;
}

.learning-paths__header h1 {
  margin-bottom: 0.35rem;
}

.learning-paths__header p,
.learning-paths__muted,
.learning-paths__empty {
  color: #5d645e;
}

.learning-paths__paths {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
}

.learning-paths__path-card {
  display: grid;
  gap: 0.45rem;
  border: 1px solid #d4c6b6;
  border-radius: 1rem;
  background: #fffdfa;
  padding: 1rem;
  text-align: left;
  cursor: pointer;
}

.learning-paths__path-card.is-active {
  border-color: #8d5f2a;
  background: #fbf1e2;
}

.learning-paths__path-title {
  font-weight: 700;
  color: #432b14;
}

.learning-paths__path-description {
  font-size: 0.95rem;
}

.learning-paths__path-time {
  color: #6d5738;
  font-size: 0.9rem;
}

.learning-paths__workspace {
  display: grid;
  gap: 1rem;
}

.learning-paths__progress {
  display: grid;
  gap: 0.5rem;
}

.learning-paths__progress p {
  margin: 0;
}

.learning-paths__progress-bar {
  height: 0.75rem;
  border-radius: 999px;
  background: #eee4d8;
  overflow: hidden;
}

.learning-paths__progress-bar span {
  display: block;
  height: 100%;
  width: 0;
  background: linear-gradient(90deg, #a56a2c, #d59647);
}

.learning-paths__layout {
  display: grid;
  gap: 1.25rem;
  grid-template-columns: minmax(17rem, 21rem) minmax(0, 1fr);
}

.learning-paths__steps,
.learning-paths__details {
  border: 1px solid #d9ccbc;
  border-radius: 1rem;
  background: #fffdfa;
  box-shadow: 0 14px 40px rgba(68, 49, 27, 0.07);
  padding: 1.2rem;
}

.learning-paths__steps h2,
.learning-paths__details h2 {
  margin-top: 0;
}

.learning-paths__step-list {
  display: grid;
  gap: 0.75rem;
  padding-left: 1.1rem;
}

.learning-paths__step-button {
  display: grid;
  gap: 0.2rem;
  width: 100%;
  border: 1px solid #e3d8cb;
  border-radius: 0.8rem;
  background: #fff;
  padding: 0.8rem;
  text-align: left;
  cursor: pointer;
}

.learning-paths__step-button.is-active {
  border-color: #8d5f2a;
  background: #fbf1e2;
}

.learning-paths__step-count {
  font-size: 0.8rem;
  color: #6a604f;
}

.learning-paths__step-title {
  font-weight: 600;
  color: #432b14;
}

.learning-paths__eyebrow {
  margin: 0 0 0.35rem;
  color: #6a604f;
  font-size: 0.9rem;
}

.learning-paths__primary-link {
  font-weight: 600;
}

.learning-paths__nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 1.5rem;
}

.learning-paths__nav button {
  border: 1px solid #d2c2b3;
  border-radius: 999px;
  background: #fff;
  padding: 0.55rem 0.95rem;
  font: inherit;
  cursor: pointer;
}

.learning-paths__nav button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

@media (max-width: 960px) {
  .learning-paths__layout {
    grid-template-columns: 1fr;
  }
}
</style>
HTML;

        return str_replace('__LEARNING_PATHS_DATA__', $dataJson, $template);
    }

    /**
     * @return array<string,mixed>
     */
    private function data(): array
    {
        return [
            'paths' => [
                [
                    'id' => 'learn-foundry-30',
                    'title' => 'Learn Foundry in 30 minutes',
                    'description' => 'A fast orientation through the graph-native docs model, core CLI loop, and the two new interactive docs surfaces.',
                    'estimatedTime' => '30 min',
                    'steps' => [
                        [
                            'title' => 'Start with the docs map',
                            'linkTitle' => 'Foundry Docs',
                            'href' => 'index.html',
                            'context' => 'This page explains how curated architecture docs and generated reference pages fit together so the rest of the path has clear landmarks.',
                        ],
                        [
                            'title' => 'Take the quick tour',
                            'linkTitle' => 'Quick Tour',
                            'href' => 'quick-tour.html',
                            'context' => 'This is the shortest path through compile, inspect, verify, and generated docs refresh so you can see the default Foundry workflow in one pass.',
                            'cliConcept' => [
                                'title' => 'compile graph',
                                'href' => 'command-playground.html?command=compile%20graph',
                                'context' => 'Start with the core compile loop.',
                            ],
                            'explainTarget' => [
                                'title' => 'command:compile graph',
                                'href' => 'architecture-tools.html',
                                'context' => 'A useful first explain target once you know the loop.',
                            ],
                        ],
                        [
                            'title' => 'Understand the architecture model',
                            'linkTitle' => 'How It Works',
                            'href' => 'how-it-works.html',
                            'context' => 'This page connects the semantic compiler, graph, pipeline, and generated docs so the rest of the tooling makes conceptual sense.',
                            'cliConcept' => [
                                'title' => 'inspect graph',
                                'href' => 'command-playground.html?command=inspect%20graph',
                                'context' => 'The main CLI surface for architecture reality.',
                            ],
                        ],
                        [
                            'title' => 'Ground it with a simple app',
                            'linkTitle' => 'Hello World Example',
                            'href' => 'example-hello-world.html',
                            'context' => 'A compact example makes the feature-first structure concrete before you branch into richer reference material.',
                            'explainTarget' => [
                                'title' => 'feature:publish_post',
                                'href' => 'architecture-tools.html',
                                'context' => 'Swap in a feature target when you want a story-shaped explanation.',
                            ],
                        ],
                        [
                            'title' => 'Browse commands safely',
                            'linkTitle' => 'Command Playground',
                            'href' => 'command-playground.html?command=compile%20graph',
                            'context' => 'Use the static playground to inspect signatures, examples, and JSON previews without executing anything.',
                            'cliConcept' => [
                                'title' => 'help',
                                'href' => 'command-playground.html?command=help',
                                'context' => 'The command registry entry point.',
                            ],
                            'explainTarget' => [
                                'title' => 'command:compile graph',
                                'href' => 'architecture-tools.html',
                                'context' => 'Explain targets also exist for CLI commands.',
                            ],
                        ],
                        [
                            'title' => 'Explore the graph visually',
                            'linkTitle' => 'Architecture Explorer',
                            'href' => 'architecture-explorer.html',
                            'context' => 'The explorer makes graph relationships easier to navigate once you understand the compile and inspect loop.',
                            'cliConcept' => [
                                'title' => 'graph inspect',
                                'href' => 'command-playground.html?command=graph%20inspect',
                                'context' => 'The stable alias behind graph slices.',
                            ],
                            'explainTarget' => [
                                'title' => 'route:POST /posts',
                                'href' => 'architecture-tools.html',
                                'context' => 'Routes are another strong entry point for explain.',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'build-first-extension',
                    'title' => 'Build your first extension',
                    'description' => 'A structured path through extension contracts, lifecycle rules, compatibility expectations, and the supporting CLI surfaces.',
                    'estimatedTime' => '35 min',
                    'steps' => [
                        [
                            'title' => 'Review the supported surface',
                            'linkTitle' => 'Public API Policy',
                            'href' => 'public-api-policy.html',
                            'context' => 'Start here so you know which extension hooks are stable and which compiler internals remain off-limits.',
                            'cliConcept' => [
                                'title' => 'inspect api-surface',
                                'href' => 'command-playground.html?command=inspect%20api-surface',
                                'context' => 'Use this to inspect the declared surface area.',
                            ],
                        ],
                        [
                            'title' => 'Read the extension author contract',
                            'linkTitle' => 'Extension Author Guide',
                            'href' => 'extension-author-guide.html',
                            'context' => 'This guide covers descriptors, lifecycle expectations, diagnostics, and explain contributions for real extension work.',
                            'cliConcept' => [
                                'title' => 'inspect extensions',
                                'href' => 'command-playground.html?command=inspect%20extensions',
                                'context' => 'The primary inspection surface for registered extensions.',
                            ],
                            'explainTarget' => [
                                'title' => 'extension:foundry.demo',
                                'href' => 'architecture-tools.html',
                                'context' => 'A representative extension explain target.',
                            ],
                        ],
                        [
                            'title' => 'Study lifecycle and compatibility',
                            'linkTitle' => 'Extensions And Migrations',
                            'href' => 'extensions-and-migrations.html',
                            'context' => 'This is where Foundry explains deterministic loading, packs, compatibility checks, migrations, and codemods as one lifecycle.',
                            'cliConcept' => [
                                'title' => 'verify extensions',
                                'href' => 'command-playground.html?command=verify%20extensions',
                                'context' => 'The verification surface for extension health.',
                            ],
                        ],
                        [
                            'title' => 'Use the example index as a map',
                            'linkTitle' => 'Example Applications',
                            'href' => 'example-applications.html',
                            'context' => 'The example catalog points you to the reference extension example and shows where extension work fits in the broader example set.',
                        ],
                        [
                            'title' => 'Rehearse the extension CLI flow',
                            'linkTitle' => 'Command Playground',
                            'href' => 'command-playground.html?command=inspect%20extensions',
                            'context' => 'Compare inspect, compatibility, and verify commands in one safe browser view before you run them locally.',
                            'cliConcept' => [
                                'title' => 'inspect compatibility',
                                'href' => 'command-playground.html?command=inspect%20compatibility',
                                'context' => 'Review compatibility outputs alongside extension inspection.',
                            ],
                            'explainTarget' => [
                                'title' => 'command:inspect extensions',
                                'href' => 'architecture-tools.html',
                                'context' => 'Command-level explain targets help with tooling discovery too.',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'execution-pipeline',
                    'title' => 'Understand the execution pipeline',
                    'description' => 'Follow the path from the graph-wide architecture model to pipeline-specific docs, CLI surfaces, and explain-oriented inspection targets.',
                    'estimatedTime' => '25 min',
                    'steps' => [
                        [
                            'title' => 'Reconnect the big picture',
                            'linkTitle' => 'How It Works',
                            'href' => 'how-it-works.html',
                            'context' => 'This page explains where the execution pipeline sits in the larger graph-native mental model.',
                        ],
                        [
                            'title' => 'Read the pipeline reference',
                            'linkTitle' => 'Execution Pipeline',
                            'href' => 'execution-pipeline.html',
                            'context' => 'This is the core reference for stages, guards, interceptors, diagnostics, and the development loop for pipeline work.',
                            'cliConcept' => [
                                'title' => 'verify pipeline',
                                'href' => 'command-playground.html?command=verify%20pipeline',
                                'context' => 'The verification command for pipeline completeness and ordering.',
                            ],
                            'explainTarget' => [
                                'title' => 'pipeline_stage:auth',
                                'href' => 'architecture-tools.html',
                                'context' => 'Pipeline stages are first-class explain targets.',
                            ],
                        ],
                        [
                            'title' => 'See the tooling layer',
                            'linkTitle' => 'Architecture Tools',
                            'href' => 'architecture-tools.html',
                            'context' => 'This page shows how inspect, doctor, explain, graph export, and prompt surfaces all consume the same compiled graph and pipeline data.',
                            'cliConcept' => [
                                'title' => 'inspect execution-plan',
                                'href' => 'command-playground.html?command=inspect%20execution-plan',
                                'context' => 'The most direct execution-plan inspection surface.',
                            ],
                            'explainTarget' => [
                                'title' => 'route:POST /posts',
                                'href' => 'architecture-tools.html',
                                'context' => 'Routes expose execution flow clearly.',
                            ],
                        ],
                        [
                            'title' => 'Compare command surfaces side by side',
                            'linkTitle' => 'Command Playground',
                            'href' => 'command-playground.html?command=inspect%20execution-plan',
                            'context' => 'Use the command playground to compare execution-plan, graph-inspection, and pipeline verification commands without leaving the docs.',
                            'cliConcept' => [
                                'title' => 'graph inspect',
                                'href' => 'command-playground.html?command=graph%20inspect',
                                'context' => 'Useful when you want the broader graph slice beside pipeline-specific commands.',
                            ],
                            'explainTarget' => [
                                'title' => 'command:inspect execution-plan',
                                'href' => 'architecture-tools.html',
                                'context' => 'Explain targets also cover pipeline-oriented commands.',
                            ],
                        ],
                        [
                            'title' => 'Use the graph view for relationships',
                            'linkTitle' => 'Architecture Explorer',
                            'href' => 'architecture-explorer.html',
                            'context' => 'The explorer helps you visually connect routes, features, events, and pipeline-adjacent nodes after reading the reference material.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
