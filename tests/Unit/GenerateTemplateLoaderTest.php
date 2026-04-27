<?php

declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generate\GenerateTemplateLoader;
use Foundry\Generate\GenerateTemplateResolver;
use Foundry\Generate\GenerateWorkflowLoader;
use Foundry\Support\FoundryError;
use Foundry\Support\Paths;
use Foundry\Tests\Fixtures\TempProject;
use PHPUnit\Framework\TestCase;

final class GenerateTemplateLoaderTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();
    }

    public function test_loader_and_resolver_build_deterministic_single_template_definition(): void
    {
        $this->writeTemplate('feature.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'feature.recipe',
            'description' => 'Create a feature from deterministic params.',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
                'target' => ['type' => 'string', 'default' => 'comments_system'],
                'flags' => ['type' => 'array', 'default' => ['fast']],
                'config' => ['type' => 'object', 'default' => ['tier' => 'core']],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.name}} feature',
                    'mode' => 'new',
                    'target' => '{{parameters.target}}',
                    'packs' => '{{parameters.flags}}',
                    'metadata' => '{{parameters.config}}',
                ],
            ],
        ]);

        $paths = Paths::fromCwd($this->project->root);
        $loader = new GenerateTemplateLoader($paths);
        $resolver = new GenerateTemplateResolver();

        $templateA = $loader->load('feature.recipe');
        $templateB = $loader->load('feature.recipe');
        $resolvedA = $resolver->resolve($templateA, [
            'name' => 'comments',
            'flags' => '["fast","safe"]',
            'config' => '{"tier":"core","enabled":true}',
        ]);
        $resolvedB = $resolver->resolve($templateB, [
            'name' => 'comments',
            'flags' => '["fast","safe"]',
            'config' => '{"tier":"core","enabled":true}',
        ]);

        $this->assertSame($templateA->templateId, $templateB->templateId);
        $this->assertSame($resolvedA->resolvedDefinition, $resolvedB->resolvedDefinition);
        $this->assertSame('Create comments feature', $resolvedA->resolvedDefinition['intent']);
        $this->assertSame('comments_system', $resolvedA->resolvedDefinition['target']);
        $this->assertSame(['fast', 'safe'], $resolvedA->resolvedDefinition['packs']);
        $this->assertSame(['tier' => 'core', 'enabled' => true], $resolvedA->resolvedDefinition['metadata']);
        $this->assertSame('feature.recipe', $resolvedA->metadata()['template_id']);
    }

    public function test_loader_and_resolver_support_workflow_templates_and_nested_parameter_paths(): void
    {
        $this->writeTemplate('workflow.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'workflow.recipe',
            'description' => 'Create a workflow from deterministic params.',
            'parameters' => [
                'resource' => ['type' => 'string', 'required' => true],
                'shared' => ['type' => 'object', 'default' => ['suffix' => 'audit']],
            ],
            'generate' => [
                'type' => 'workflow',
                'definition' => [
                    'shared_context' => [
                        'resource' => '{{parameters.resource}}',
                        'suffix' => '{{parameters.shared.suffix}}',
                    ],
                    'steps' => [
                        [
                            'id' => 'create_resource',
                            'intent' => 'Create {{shared.resource}}',
                            'mode' => 'new',
                        ],
                        [
                            'id' => 'create_follow_up',
                            'intent' => 'Create {{steps.create_resource.feature}} {{shared.suffix}}',
                            'mode' => 'new',
                            'dependencies' => ['create_resource'],
                        ],
                    ],
                ],
            ],
        ]);

        $paths = Paths::fromCwd($this->project->root);
        $template = (new GenerateTemplateLoader($paths))->load('workflow.recipe');
        $resolution = (new GenerateTemplateResolver())->resolve($template, ['resource' => 'comments']);
        $workflow = (new GenerateWorkflowLoader($paths))->loadDefinition($resolution->resolvedDefinition, '.foundry/templates/workflow.json');

        $this->assertSame('.foundry/templates/workflow.json', $workflow->path);
        $this->assertSame('comments', $workflow->sharedContext['resource']);
        $this->assertSame('audit', $workflow->sharedContext['suffix']);
        $this->assertCount(2, $workflow->steps);
        $this->assertSame('Create {{shared.resource}}', $workflow->steps[0]->rawIntent);
        $this->assertSame(['resource' => 'comments', 'suffix' => 'audit'], $resolution->resolvedDefinition['shared_context']);
    }

    public function test_definition_to_array_and_loader_reject_missing_description_invalid_parameter_shapes_and_invalid_generate_shape(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $loader = new GenerateTemplateLoader($paths);

        $this->writeTemplate('valid.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'valid.template',
            'description' => 'Valid template',
            'parameters' => [
                'threshold' => ['type' => 'number', 'default' => 1.5],
                'enabled' => ['type' => 'boolean', 'default' => false],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Repair comments',
                    'mode' => 'repair',
                    'target' => 'comments_system',
                ],
            ],
        ]);

        $definition = $loader->load('valid.template');
        $this->assertSame('foundry.generate.template.v1', $definition->toArray()['schema']);
        $this->assertSame('valid.template', $definition->toArray()['template_id']);
        $this->assertSame('single', $definition->toArray()['generate']['type']);
        $this->assertSame(1.5, $definition->toArray()['parameters']['threshold']['default']);
        $this->assertFalse($definition->toArray()['parameters']['enabled']['default']);

        $invalidCases = [
            'missing-description.json' => [
                'payload' => [
                    'schema' => 'foundry.generate.template.v1',
                    'template_id' => 'missing.description',
                    'parameters' => [],
                    'generate' => ['type' => 'single', 'definition' => ['intent' => 'Create comments', 'mode' => 'new']],
                ],
                'code' => 'GENERATE_TEMPLATE_DESCRIPTION_REQUIRED',
            ],
            'invalid-parameter-shape.json' => [
                'payload' => [
                    'schema' => 'foundry.generate.template.v1',
                    'template_id' => 'parameter.shape',
                    'description' => 'Bad parameter shape',
                    'parameters' => ['name' => 'string'],
                    'generate' => ['type' => 'single', 'definition' => ['intent' => 'Create comments', 'mode' => 'new']],
                ],
                'code' => 'GENERATE_TEMPLATE_PARAMETER_INVALID',
            ],
            'invalid-default.json' => [
                'payload' => [
                    'schema' => 'foundry.generate.template.v1',
                    'template_id' => 'invalid.default',
                    'description' => 'Bad default',
                    'parameters' => ['name' => ['type' => 'number', 'default' => 'abc']],
                    'generate' => ['type' => 'single', 'definition' => ['intent' => 'Create comments', 'mode' => 'new']],
                ],
                'code' => 'GENERATE_TEMPLATE_PARAMETER_TYPE_INVALID',
            ],
            'missing-generate.json' => [
                'payload' => [
                    'schema' => 'foundry.generate.template.v1',
                    'template_id' => 'missing.generate',
                    'description' => 'Missing generate',
                    'parameters' => [],
                ],
                'code' => 'GENERATE_TEMPLATE_GENERATE_REQUIRED',
            ],
            'invalid-generate-type.json' => [
                'payload' => [
                    'schema' => 'foundry.generate.template.v1',
                    'template_id' => 'invalid.generate.type',
                    'description' => 'Bad generate type',
                    'parameters' => [],
                    'generate' => ['type' => 'batch', 'definition' => []],
                ],
                'code' => 'GENERATE_TEMPLATE_GENERATE_TYPE_INVALID',
            ],
            'invalid-definition.json' => [
                'payload' => [
                    'schema' => 'foundry.generate.template.v1',
                    'template_id' => 'invalid.definition',
                    'description' => 'Bad definition',
                    'parameters' => [],
                    'generate' => ['type' => 'single', 'definition' => 'bad'],
                ],
                'code' => 'GENERATE_TEMPLATE_DEFINITION_INVALID',
            ],
        ];

        foreach ($invalidCases as $filename => $case) {
            $this->writeTemplate($filename, $case['payload']);

            try {
                $loader->load((string) $case['payload']['template_id']);
                self::fail('Expected template validation failure for ' . $filename . '.');
            } catch (FoundryError $error) {
                $this->assertSame($case['code'], $error->errorCode);
            }

            @unlink($this->project->root . '/.foundry/templates/' . $filename);
        }
    }

    public function test_resolver_supports_number_boolean_array_object_and_optional_parameter_shapes(): void
    {
        $this->writeTemplate('resolver.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'resolver.template',
            'description' => 'Resolver coverage template',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
                'count' => ['type' => 'number', 'required' => true],
                'enabled' => ['type' => 'boolean', 'required' => true],
                'tags' => ['type' => 'array', 'required' => true],
                'config' => ['type' => 'object', 'required' => true],
                'optional_note' => ['type' => 'string', 'required' => false],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Modify {{parameters.name}} {{parameters.count}}',
                    'mode' => 'modify',
                    'target' => '{{parameters.name}}',
                    'enabled' => '{{parameters.enabled}}',
                    'tags' => '{{parameters.tags}}',
                    'config' => '{{parameters.config}}',
                    'note' => '{{parameters.optional_note}}',
                ],
            ],
        ]);

        $paths = Paths::fromCwd($this->project->root);
        $template = (new GenerateTemplateLoader($paths))->load('resolver.template');
        $resolved = (new GenerateTemplateResolver())->resolve($template, [
            'name' => 'comments_system',
            'count' => '2.5',
            'enabled' => 'false',
            'tags' => '["fast","safe"]',
            'config' => '{"tier":"core","retries":2}',
        ]);

        $this->assertSame('Modify comments_system 2.5', $resolved->resolvedDefinition['intent']);
        $this->assertSame('comments_system', $resolved->resolvedDefinition['target']);
        $this->assertFalse($resolved->resolvedDefinition['enabled']);
        $this->assertSame(['fast', 'safe'], $resolved->resolvedDefinition['tags']);
        $this->assertSame(['tier' => 'core', 'retries' => 2], $resolved->resolvedDefinition['config']);
        $this->assertNull($resolved->resolvedDefinition['note']);
        $this->assertSame(2.5, $resolved->resolvedParameters['count']);
        $this->assertFalse($resolved->resolvedParameters['enabled']);
    }

    public function test_loader_requires_non_empty_template_id_and_resolver_covers_true_integer_invalid_json_shapes_and_invalid_root_references(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $loader = new GenerateTemplateLoader($paths);

        try {
            $loader->load('');
            self::fail('Expected missing template id failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_ID_REQUIRED', $error->errorCode);
        }

        try {
            $loader->load('missing.template');
            self::fail('Expected missing template failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_NOT_FOUND', $error->errorCode);
        }

        $this->writeTemplate('shapes.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'shapes.template',
            'description' => 'Shape validation template',
            'parameters' => [
                'count' => ['type' => 'number', 'required' => true],
                'enabled' => ['type' => 'boolean', 'required' => true],
                'tags' => ['type' => 'array', 'required' => true],
                'config' => ['type' => 'object', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Repair comments {{parameters.count}}',
                    'mode' => 'repair',
                    'target' => 'comments_system',
                    'enabled' => '{{parameters.enabled}}',
                    'tags' => '{{parameters.tags}}',
                    'config' => '{{parameters.config}}',
                ],
            ],
        ]);

        $resolver = new GenerateTemplateResolver();
        $template = $loader->load('shapes.template');
        $resolved = $resolver->resolve($template, [
            'count' => '2',
            'enabled' => 'true',
            'tags' => '["core"]',
            'config' => '{"tier":"core"}',
        ]);

        $this->assertSame(2, $resolved->resolvedParameters['count']);
        $this->assertTrue($resolved->resolvedParameters['enabled']);
        $this->assertSame(['core'], $resolved->resolvedDefinition['tags']);
        $this->assertSame(['tier' => 'core'], $resolved->resolvedDefinition['config']);

        $invalidCases = [
            [
                'params' => ['count' => '2', 'enabled' => 'true', 'tags' => '{"not":"a-list"}', 'config' => '{"tier":"core"}'],
                'code' => 'GENERATE_TEMPLATE_PARAMETER_VALUE_INVALID',
            ],
            [
                'params' => ['count' => '2', 'enabled' => 'true', 'tags' => '["core"]', 'config' => '["wrong"]'],
                'code' => 'GENERATE_TEMPLATE_PARAMETER_VALUE_INVALID',
            ],
        ];

        foreach ($invalidCases as $case) {
            try {
                $resolver->resolve($template, $case['params']);
                self::fail('Expected structured parameter shape failure.');
            } catch (FoundryError $error) {
                $this->assertSame($case['code'], $error->errorCode);
            }
        }

        $this->writeTemplate('invalid-root.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'invalid.root',
            'description' => 'Invalid root reference template',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{shared.name}}',
                    'mode' => 'new',
                ],
            ],
        ]);

        try {
            $resolver->resolve($loader->load('invalid.root'), ['name' => 'comments']);
            self::fail('Expected invalid placeholder root failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_PARAMETER_REFERENCE_INVALID', $error->errorCode);
        }
    }

    public function test_loader_and_resolver_reject_invalid_schema_duplicate_ids_missing_required_params_invalid_types_and_invalid_references(): void
    {
        $paths = Paths::fromCwd($this->project->root);
        $loader = new GenerateTemplateLoader($paths);
        $resolver = new GenerateTemplateResolver();

        $this->writeTemplate('bad-schema.json', [
            'schema' => 'foundry.generate.template.v0',
            'template_id' => 'bad.schema',
            'description' => 'Bad schema',
            'parameters' => [],
            'generate' => ['type' => 'single', 'definition' => ['intent' => 'Create comments', 'mode' => 'new']],
        ]);
        try {
            $loader->load('bad.schema');
            self::fail('Expected invalid schema failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_SCHEMA_INVALID', $error->errorCode);
        }
        @unlink($this->project->root . '/.foundry/templates/bad-schema.json');

        $this->writeTemplate('duplicate-a.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'duplicate.template',
            'description' => 'Duplicate A',
            'parameters' => [],
            'generate' => ['type' => 'single', 'definition' => ['intent' => 'Create A', 'mode' => 'new']],
        ]);
        $this->writeTemplate('duplicate-b.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'duplicate.template',
            'description' => 'Duplicate B',
            'parameters' => [],
            'generate' => ['type' => 'single', 'definition' => ['intent' => 'Create B', 'mode' => 'new']],
        ]);
        try {
            $loader->load('duplicate.template');
            self::fail('Expected duplicate template id failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_ID_DUPLICATE', $error->errorCode);
        }

        $this->writeTemplate('params.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'params.template',
            'description' => 'Param validation template',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
                'enabled' => ['type' => 'boolean', 'required' => true],
                'config' => ['type' => 'object', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.name}}',
                    'mode' => 'new',
                    'summary' => 'Enabled={{parameters.enabled}}',
                    'invalid' => 'Value {{parameters.config}}',
                ],
            ],
        ]);

        $template = $loader->load('params.template');
        try {
            $resolver->resolve($template, []);
            self::fail('Expected required parameter failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_PARAMETER_REQUIRED', $error->errorCode);
        }

        try {
            $resolver->resolve($template, ['name' => 'comments', 'enabled' => 'yes', 'config' => '{"tier":"core"}']);
            self::fail('Expected boolean coercion failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_PARAMETER_VALUE_INVALID', $error->errorCode);
        }

        try {
            $resolver->resolve($template, ['name' => 'comments', 'enabled' => 'true', 'config' => '{"tier":"core"}', 'extra' => 'x']);
            self::fail('Expected unknown parameter failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_PARAMETER_UNKNOWN', $error->errorCode);
        }

        try {
            $resolver->resolve($template, ['name' => 'comments', 'enabled' => 'true', 'config' => '{"tier":"core"}']);
            self::fail('Expected invalid interpolation failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_PARAMETER_INTERPOLATION_INVALID', $error->errorCode);
        }

        $this->writeTemplate('reference.json', [
            'schema' => 'foundry.generate.template.v1',
            'template_id' => 'reference.template',
            'description' => 'Reference validation template',
            'parameters' => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'generate' => [
                'type' => 'single',
                'definition' => [
                    'intent' => 'Create {{parameters.missing}}',
                    'mode' => 'new',
                ],
            ],
        ]);

        try {
            $resolver->resolve($loader->load('reference.template'), ['name' => 'comments']);
            self::fail('Expected missing reference failure.');
        } catch (FoundryError $error) {
            $this->assertSame('GENERATE_TEMPLATE_PARAMETER_REFERENCE_INVALID', $error->errorCode);
        }
    }

    /**
     * @param array<string,mixed> $template
     */
    private function writeTemplate(string $filename, array $template): void
    {
        $dir = $this->project->root . '/.foundry/templates';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $dir . '/' . $filename,
            json_encode($template, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
        );
    }
}
