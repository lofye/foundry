<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'say_hello' => 
  array (
    'kind' => 'http',
    'description' => 'Minimal hello-world route for onboarding and architecture inspection.',
    'route' => 
    array (
      'method' => 'GET',
      'path' => '/hello',
    ),
    'input_schema' => 'app/features/say_hello/input.schema.json',
    'output_schema' => 'app/features/say_hello/output.schema.json',
    'auth' => 
    array (
      'required' => false,
      'public' => true,
      'strategies' => 
      array (
      ),
      'permissions' => 
      array (
      ),
    ),
    'database' => 
    array (
      'reads' => 
      array (
      ),
      'writes' => 
      array (
      ),
      'transactions' => 'optional',
      'queries' => 
      array (
      ),
    ),
    'cache' => 
    array (
      'reads' => 
      array (
      ),
      'writes' => 
      array (
      ),
      'invalidate' => 
      array (
      ),
      'entries' => 
      array (
      ),
    ),
    'events' => 
    array (
      'emit' => 
      array (
      ),
      'emit_definitions' => 
      array (
      ),
      'subscribe' => 
      array (
      ),
    ),
    'jobs' => 
    array (
      'dispatch' => 
      array (
      ),
      'definitions' => 
      array (
      ),
    ),
    'rate_limit' => 
    array (
    ),
    'csrf' => 
    array (
    ),
    'resource' => 
    array (
    ),
    'listing' => 
    array (
    ),
    'uploads' => 
    array (
    ),
    'ui' => 
    array (
    ),
    'tests' => 
    array (
      'required' => 
      array (
        0 => 'contract',
        1 => 'feature',
      ),
      'files' => 
      array (
        0 => 'app/features/say_hello/tests/say_hello_contract_test.php',
        1 => 'app/features/say_hello/tests/say_hello_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/say_hello',
    'action_class' => 'App\\Features\\SayHello\\Action',
  ),
);
