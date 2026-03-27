<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'publish_post' => 
  array (
    'kind' => 'http',
    'description' => 'Create a new post and optionally publish it immediately.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/posts',
    ),
    'input_schema' => 'app/features/publish_post/input.schema.json',
    'output_schema' => 'app/features/publish_post/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'posts.create',
      ),
      'public' => false,
    ),
    'database' => 
    array (
      'reads' => 
      array (
        0 => 'users',
      ),
      'writes' => 
      array (
        0 => 'posts',
      ),
      'transactions' => 'required',
      'queries' => 
      array (
        0 => 'find_user_by_id',
        1 => 'insert_post',
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
        0 => 'posts:list',
      ),
      'entries' => 
      array (
        'posts:list' => 
        array (
          'kind' => 'computed',
          'ttl_seconds' => 300,
          'invalidated_by' => 
          array (
            0 => 'publish_post',
          ),
        ),
      ),
    ),
    'events' => 
    array (
      'emit' => 
      array (
        0 => 'post.created',
      ),
      'emit_definitions' => 
      array (
        'post.created' => 
        array (
          'type' => 'object',
          'additionalProperties' => false,
          'required' => 
          array (
            0 => 'post_id',
            1 => 'author_id',
            2 => 'status',
          ),
          'properties' => 
          array (
            'post_id' => 
            array (
              'type' => 'string',
            ),
            'author_id' => 
            array (
              'type' => 'string',
            ),
            'status' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
      ),
      'subscribe' => 
      array (
      ),
    ),
    'jobs' => 
    array (
      'dispatch' => 
      array (
        0 => 'notify_followers',
      ),
      'definitions' => 
      array (
        'notify_followers' => 
        array (
          'input_schema' => 
          array (
            'type' => 'object',
            'additionalProperties' => false,
            'required' => 
            array (
              0 => 'post_id',
            ),
            'properties' => 
            array (
              'post_id' => 
              array (
                'type' => 'string',
              ),
            ),
          ),
          'queue' => 'default',
          'retry' => 
          array (
            'max_attempts' => 5,
            'backoff_seconds' => 
            array (
              0 => 5,
              1 => 30,
              2 => 120,
              3 => 300,
              4 => 600,
            ),
          ),
          'timeout_seconds' => 60,
          'idempotency_key' => 'post_id',
        ),
      ),
    ),
    'rate_limit' => 
    array (
      'bucket' => 'post_create',
      'cost' => 1,
      'strategy' => 'user',
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
        0 => 'auth',
        1 => 'contract',
        2 => 'feature',
      ),
      'files' => 
      array (
        0 => 'app/features/publish_post/tests/publish_post_auth_test.php',
        1 => 'app/features/publish_post/tests/publish_post_contract_test.php',
        2 => 'app/features/publish_post/tests/publish_post_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk' => 'medium',
      'notes_file' => 'prompts.md',
      'risk_level' => 'medium',
    ),
    'base_path' => 'app/features/publish_post',
    'action_class' => 'App\\Features\\PublishPost\\Action',
  ),
);
