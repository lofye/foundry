<?php
declare(strict_types=1);

namespace Foundry\Config;

final class ConfigSchemaCatalog
{
    public function registry(): ConfigSchemaRegistry
    {
        $registry = new ConfigSchemaRegistry();

        foreach ($this->schemas() as $id => $schema) {
            $registry->register($id, $schema);
        }

        return $registry;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function schemas(): array
    {
        return [
            'bootstrap.app' => [
                '$id' => 'bootstrap.app',
                'title' => 'Foundry Bootstrap App Config',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1],
                    'env' => ['type' => 'string', 'minLength' => 1],
                    'debug' => ['type' => 'boolean'],
                    'starter' => ['type' => 'string', 'enum' => ['minimal', 'standard', 'api-first']],
                ],
                'x-foundry-path' => 'bootstrap/app.php',
                'x-foundry-optional' => true,
            ],
            'bootstrap.providers' => [
                '$id' => 'bootstrap.providers',
                'title' => 'Foundry Bootstrap Providers',
                'type' => 'array',
                'items' => ['type' => 'string', 'minLength' => 1],
                'uniqueItems' => true,
                'x-foundry-path' => 'bootstrap/providers.php',
                'x-foundry-optional' => true,
            ],
            'config.app' => [
                '$id' => 'config.app',
                'title' => 'Foundry App Config',
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1],
                    'starter' => ['type' => 'string', 'enum' => ['minimal', 'standard', 'api-first']],
                    'routing' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'base_path' => ['type' => 'string', 'pattern' => '^/.*|^$'],
                            'trailing_slash_strategy' => ['type' => 'string', 'enum' => ['ignore', 'redirect', 'strip']],
                        ],
                    ],
                ],
                'x-foundry-path' => 'config/app.php',
                'x-foundry-optional' => true,
            ],
            'config.auth' => [
                '$id' => 'config.auth',
                'title' => 'Foundry Auth Config',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['default'],
                'properties' => [
                    'default' => ['type' => 'string', 'minLength' => 1],
                    'development_header' => ['type' => 'string', 'minLength' => 1],
                    'strategies' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'bearer' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'header' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                            'session' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'cookie' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                            'api_key' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'header' => ['type' => 'string', 'minLength' => 1],
                                    'query' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
                'x-foundry-path' => 'config/auth.php',
                'x-foundry-optional' => true,
            ],
            'config.database' => [
                '$id' => 'config.database',
                'title' => 'Foundry Database Config',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['default'],
                'properties' => [
                    'default' => ['type' => 'string', 'minLength' => 1],
                    'connections' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'sqlite' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['dsn'],
                                'properties' => [
                                    'dsn' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
                'x-foundry-path' => 'config/database.php',
                'x-foundry-optional' => true,
            ],
            'config.cache' => [
                '$id' => 'config.cache',
                'title' => 'Foundry Cache Config',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['default'],
                'properties' => [
                    'default' => ['type' => 'string', 'enum' => ['array', 'redis']],
                    'stores' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'array' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [],
                            ],
                            'redis' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['connection'],
                                'properties' => [
                                    'connection' => ['type' => 'string', 'minLength' => 1],
                                    'prefix' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
                'x-foundry-path' => 'config/cache.php',
                'x-foundry-optional' => true,
            ],
            'config.queue' => [
                '$id' => 'config.queue',
                'title' => 'Foundry Queue Config',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['default'],
                'properties' => [
                    'default' => ['type' => 'string', 'enum' => ['sync', 'database', 'redis']],
                    'drivers' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'sync' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [],
                            ],
                            'database' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'table' => ['type' => 'string', 'minLength' => 1],
                                    'retry_after_seconds' => ['type' => 'integer', 'minimum' => 1],
                                ],
                            ],
                            'redis' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['connection', 'queue'],
                                'properties' => [
                                    'connection' => ['type' => 'string', 'minLength' => 1],
                                    'queue' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
                'x-foundry-path' => 'config/queue.php',
                'x-foundry-optional' => true,
            ],
            'config.storage' => [
                '$id' => 'config.storage',
                'title' => 'Foundry Storage Config',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['default', 'root'],
                'properties' => [
                    'default' => ['type' => 'string', 'enum' => ['local']],
                    'root' => ['type' => 'string', 'minLength' => 1],
                ],
                'x-foundry-path' => 'config/storage.php',
                'x-foundry-optional' => true,
            ],
            'config.ai' => [
                '$id' => 'config.ai',
                'title' => 'Foundry AI Config',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['default'],
                'properties' => [
                    'default' => ['type' => 'string', 'minLength' => 1],
                    'providers' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'object',
                            'properties' => [
                                'driver' => ['type' => 'string', 'minLength' => 1],
                                'factory' => ['type' => 'string', 'minLength' => 1],
                                'model' => ['type' => 'string', 'minLength' => 1],
                                'content' => ['type' => 'string'],
                                'input_tokens' => ['type' => 'integer', 'minimum' => 0],
                                'output_tokens' => ['type' => 'integer', 'minimum' => 0],
                                'cost_estimate' => ['type' => 'number', 'minimum' => 0],
                                'base_url' => ['type' => 'string', 'minLength' => 1],
                                'api_key_env' => ['type' => 'string', 'minLength' => 1],
                            ],
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'x-foundry-path' => 'config/ai.php',
                'x-foundry-optional' => true,
            ],
            'routing.route' => [
                '$id' => 'routing.route',
                'title' => 'Foundry Feature Route',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['method', 'path'],
                'properties' => [
                    'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD']],
                    'path' => ['type' => 'string', 'pattern' => '^/'],
                ],
            ],
            'definition.search_index' => [
                '$id' => 'definition.search_index',
                'title' => 'Foundry Search Index Definition',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['version', 'index', 'adapter', 'resource', 'source', 'fields'],
                'properties' => [
                    'version' => ['type' => 'integer', 'enum' => [1]],
                    'index' => ['type' => 'string', 'minLength' => 1],
                    'adapter' => ['type' => 'string', 'enum' => ['sql', 'meilisearch']],
                    'resource' => ['type' => 'string', 'minLength' => 1],
                    'source' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['table', 'primary_key'],
                        'properties' => [
                            'table' => ['type' => 'string', 'minLength' => 1],
                            'primary_key' => ['type' => 'string', 'minLength' => 1],
                        ],
                    ],
                    'fields' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'minLength' => 1],
                        'uniqueItems' => true,
                        'minItems' => 1,
                    ],
                    'filters' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'minLength' => 1],
                        'uniqueItems' => true,
                    ],
                ],
            ],
            'extension.registration' => [
                '$id' => 'extension.registration',
                'title' => 'Foundry Extension Registration',
                'type' => 'array',
                'items' => ['type' => 'string', 'minLength' => 1],
                'uniqueItems' => true,
            ],
            'extension.descriptor' => [
                '$id' => 'extension.descriptor',
                'title' => 'Foundry Extension Descriptor',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['schema_version', 'name', 'version', 'framework_version_constraint', 'graph_version_constraint', 'dependencies', 'provides'],
                'properties' => [
                    'schema_version' => ['type' => 'integer', 'enum' => [1]],
                    'name' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:[._-][a-z0-9]+)*$'],
                    'version' => ['type' => 'string', 'minLength' => 1],
                    'description' => ['type' => 'string'],
                    'framework_version_constraint' => ['type' => 'string', 'minLength' => 1],
                    'graph_version_constraint' => ['type' => 'string', 'minLength' => 1],
                    'dependencies' => $this->extensionDependencySchema(),
                    'provides' => $this->extensionProvideSchema(),
                ],
            ],
            'extension.pack' => [
                '$id' => 'extension.pack',
                'title' => 'Foundry Pack Definition',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['schema_version', 'name', 'version', 'extension', 'framework_version_constraint', 'graph_version_constraint'],
                'properties' => [
                    'schema_version' => ['type' => 'integer', 'enum' => [1]],
                    'name' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:[._-][a-z0-9]+)*$'],
                    'version' => ['type' => 'string', 'minLength' => 1],
                    'extension' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:[._-][a-z0-9]+)*$'],
                    'description' => ['type' => 'string'],
                    'provided_capabilities' => $this->stringListSchema(),
                    'required_capabilities' => $this->stringListSchema(),
                    'dependencies' => $this->stringListSchema(),
                    'optional_dependencies' => $this->stringListSchema(),
                    'conflicts_with' => $this->stringListSchema(),
                    'framework_version_constraint' => ['type' => 'string', 'minLength' => 1],
                    'graph_version_constraint' => ['type' => 'string', 'minLength' => 1],
                    'generators' => $this->stringListSchema(),
                    'inspect_surfaces' => $this->stringListSchema(),
                    'definition_formats' => $this->stringListSchema(),
                    'migration_rules' => $this->stringListSchema(),
                    'verifiers' => $this->stringListSchema(),
                    'docs_emitters' => $this->stringListSchema(),
                    'examples' => $this->stringListSchema(),
                ],
            ],
            'pipeline.stage_definition' => [
                '$id' => 'pipeline.stage_definition',
                'title' => 'Foundry Pipeline Stage Definition',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['name', 'priority'],
                'properties' => [
                    'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                    'after_stage' => ['type' => ['string', 'null']],
                    'before_stage' => ['type' => ['string', 'null']],
                    'priority' => ['type' => 'integer', 'minimum' => 0],
                    'extension' => ['type' => ['string', 'null']],
                ],
            ],
            'pipeline.interceptor' => [
                '$id' => 'pipeline.interceptor',
                'title' => 'Foundry Pipeline Interceptor',
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'stage', 'priority', 'dangerous'],
                'properties' => [
                    'id' => ['type' => 'string', 'minLength' => 1],
                    'stage' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                    'priority' => ['type' => 'integer', 'minimum' => 0],
                    'dangerous' => ['type' => 'boolean'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extensionDependencySchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['required_extensions', 'optional_extensions', 'conflicts_with_extensions'],
            'properties' => [
                'required_extensions' => $this->identifierListSchema(),
                'optional_extensions' => $this->identifierListSchema(),
                'conflicts_with_extensions' => $this->identifierListSchema(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extensionProvideSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'node_types',
                'passes',
                'packs',
                'definition_formats',
                'migration_rules',
                'codemods',
                'projection_outputs',
                'inspect_surfaces',
                'verifiers',
                'capabilities',
            ],
            'properties' => [
                'node_types' => $this->stringListSchema(),
                'passes' => $this->stringListSchema(),
                'packs' => $this->stringListSchema(),
                'definition_formats' => $this->stringListSchema(),
                'migration_rules' => $this->stringListSchema(),
                'codemods' => $this->stringListSchema(),
                'projection_outputs' => $this->stringListSchema(),
                'inspect_surfaces' => $this->stringListSchema(),
                'verifiers' => $this->stringListSchema(),
                'capabilities' => $this->stringListSchema(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function identifierListSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'pattern' => '^[a-z0-9]+(?:[._-][a-z0-9]+)*$',
            ],
            'uniqueItems' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stringListSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'minLength' => 1,
            ],
            'uniqueItems' => true,
        ];
    }
}
