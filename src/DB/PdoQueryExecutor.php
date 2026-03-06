<?php
declare(strict_types=1);

namespace Forge\DB;

use Forge\Observability\TraceRecorder;
use Forge\Support\ForgeError;

final class PdoQueryExecutor implements QueryExecutor
{
    /**
     * @var array<int,QueryTrace>
     */
    private array $traces = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly QueryRegistry $queries,
        private readonly ?TraceRecorder $traceRecorder = null,
    ) {
    }

    public function select(string $feature, string $queryName, array $params = []): array
    {
        $definition = $this->queries->get($feature, $queryName);
        $this->assertParams($definition, $params);

        $start = microtime(true);
        $statement = $this->connection->pdo()->prepare($definition->sql);
        if ($statement === false) {
            throw new ForgeError('DB_PREPARE_FAILED', 'db', ['feature' => $feature, 'query' => $queryName], 'Failed to prepare SQL statement.');
        }

        $statement->execute($params);
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $statement->fetchAll() ?: [];

        $duration = (microtime(true) - $start) * 1000;
        $this->recordTrace($feature, $queryName, $params, $duration, count($rows));

        return $rows;
    }

    public function execute(string $feature, string $queryName, array $params = []): int
    {
        $definition = $this->queries->get($feature, $queryName);
        $this->assertParams($definition, $params);

        $start = microtime(true);
        $statement = $this->connection->pdo()->prepare($definition->sql);
        if ($statement === false) {
            throw new ForgeError('DB_PREPARE_FAILED', 'db', ['feature' => $feature, 'query' => $queryName], 'Failed to prepare SQL statement.');
        }

        $statement->execute($params);
        $count = $statement->rowCount();

        $duration = (microtime(true) - $start) * 1000;
        $this->recordTrace($feature, $queryName, $params, $duration, $count);

        return $count;
    }

    /**
     * @return array<int,QueryTrace>
     */
    public function traces(): array
    {
        return $this->traces;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function assertParams(QueryDefinition $definition, array $params): void
    {
        foreach ($definition->placeholders as $placeholder) {
            if (!array_key_exists($placeholder, $params)) {
                throw new ForgeError(
                    'QUERY_PARAM_MISSING',
                    'validation',
                    ['feature' => $definition->feature, 'query' => $definition->name, 'param' => $placeholder],
                    'Query parameter missing.'
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function recordTrace(string $feature, string $queryName, array $params, float $duration, int $rows): void
    {
        $trace = new QueryTrace($feature, $queryName, $params, $duration, $rows);
        $this->traces[] = $trace;
        $this->traceRecorder?->record($feature, 'db', 'query_execution', ['query' => $queryName, 'rows' => $rows], $duration);
    }
}
