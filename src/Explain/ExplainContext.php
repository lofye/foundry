<?php
declare(strict_types=1);

namespace Foundry\Explain;

final class ExplainContext
{
    /**
     * @var array<string,mixed>
     */
    private array $subjectNode = [];

    private GraphNeighborhoodContext $graphNeighborhood;

    private PipelineContextData $pipeline;

    /**
     * @var array<string,mixed>
     */
    private array $commands = [
        'subject' => null,
        'candidates' => [],
    ];

    /**
     * @var array<string,mixed>
     */
    private array $workflows = [
        'items' => [],
    ];

    /**
     * @var array<string,mixed>
     */
    private array $events = [
        'subject' => null,
        'emitted' => [],
        'subscribed' => [],
        'emitters' => [],
        'subscribers' => [],
    ];

    /**
     * @var array<string,mixed>
     */
    private array $schemas = [
        'subject' => null,
        'items' => [],
        'reads' => [],
        'writes' => [],
        'fields' => [],
    ];

    /**
     * @var array<string,mixed>
     */
    private array $extensions = [
        'subject' => null,
        'items' => [],
    ];

    private DiagnosticsContextData $diagnostics;

    private DocsContextData $docs;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $impact = null;

    public function __construct(
        public readonly ExplainSubject $subject,
        public readonly string $commandPrefix,
    ) {
        $this->graphNeighborhood = new GraphNeighborhoodContext();
        $this->pipeline = new PipelineContextData();
        $this->diagnostics = new DiagnosticsContextData();
        $this->docs = new DocsContextData();
    }

    /**
     * @param array<string,mixed> $subjectNode
     */
    public function setSubjectNode(array $subjectNode): void
    {
        $this->subjectNode = $subjectNode;
    }

    /**
     * @return array<string,mixed>
     */
    public function subjectNode(): array
    {
        return $this->subjectNode;
    }

    /**
     * @param array<string,mixed> $graphNeighborhood
     */
    public function setGraphNeighborhood(array $graphNeighborhood): void
    {
        $this->graphNeighborhood = new GraphNeighborhoodContext(array_replace_recursive(
            $this->graphNeighborhood->toArray(),
            $graphNeighborhood,
        ));
    }

    public function graphNeighborhood(): GraphNeighborhoodContext
    {
        return $this->graphNeighborhood;
    }

    /**
     * @param array<string,mixed> $pipeline
     */
    public function setPipeline(array $pipeline): void
    {
        $this->pipeline = new PipelineContextData(array_replace_recursive(
            $this->pipeline->toArray(),
            $pipeline,
        ));
    }

    public function pipeline(): PipelineContextData
    {
        return $this->pipeline;
    }

    /**
     * @param array<string,mixed> $commands
     */
    public function setCommands(array $commands): void
    {
        $this->commands = array_replace_recursive($this->commands, $commands);
    }

    /**
     * @return array<string,mixed>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * @param array<string,mixed> $workflows
     */
    public function setWorkflows(array $workflows): void
    {
        $this->workflows = array_replace_recursive($this->workflows, $workflows);
    }

    /**
     * @return array<string,mixed>
     */
    public function workflows(): array
    {
        return $this->workflows;
    }

    /**
     * @param array<string,mixed> $events
     */
    public function setEvents(array $events): void
    {
        $this->events = array_replace_recursive($this->events, $events);
    }

    /**
     * @return array<string,mixed>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @param array<string,mixed> $schemas
     */
    public function setSchemas(array $schemas): void
    {
        $this->schemas = array_replace_recursive($this->schemas, $schemas);
    }

    /**
     * @return array<string,mixed>
     */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * @param array<string,mixed> $extensions
     */
    public function setExtensions(array $extensions): void
    {
        $this->extensions = array_replace_recursive($this->extensions, $extensions);
    }

    /**
     * @return array<string,mixed>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /**
     * @param array<string,mixed> $diagnostics
     */
    public function setDiagnostics(array $diagnostics): void
    {
        $this->diagnostics = new DiagnosticsContextData(array_replace_recursive(
            $this->diagnostics->toArray(),
            $diagnostics,
        ));
    }

    public function diagnostics(): DiagnosticsContextData
    {
        return $this->diagnostics;
    }

    /**
     * @param array<string,mixed> $docs
     */
    public function setDocs(array $docs): void
    {
        $this->docs = new DocsContextData(array_replace_recursive(
            $this->docs->toArray(),
            $docs,
        ));
    }

    public function docs(): DocsContextData
    {
        return $this->docs;
    }

    /**
     * @param array<string,mixed>|null $impact
     */
    public function setImpact(?array $impact): void
    {
        $this->impact = $impact;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function impact(): ?array
    {
        return $this->impact;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return [
            'subject_node' => $this->subjectNode,
            'graph_neighborhood' => $this->graphNeighborhood->toArray(),
            'pipeline' => $this->pipeline->toArray(),
            'commands' => $this->commands,
            'workflows' => $this->workflows,
            'events' => $this->events,
            'schemas' => $this->schemas,
            'extensions' => $this->extensions,
            'diagnostics' => $this->diagnostics->toArray(),
            'docs' => $this->docs->toArray(),
            'impact' => $this->impact,
        ];
    }
}
