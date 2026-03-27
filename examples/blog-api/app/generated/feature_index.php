<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'delete_post' => 
  array (
    'kind' => 'http',
    'description' => 'delete_post endpoint for blog-api.',
    'route' => 
    array (
      'method' => 'DELETE',
      'path' => '/posts/{id}',
    ),
    'input_schema' => 'app/features/delete_post/input.schema.json',
    'output_schema' => 'app/features/delete_post/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'delete_post.execute',
      ),
      'public' => false,
    ),
    'database' => 
    array (
      'reads' => 
      array (
      ),
      'writes' => 
      array (
      ),
      'transactions' => 'required',
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
      'bucket' => 'delete_post',
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
        0 => 'app/features/delete_post/tests/delete_post_auth_test.php',
        1 => 'app/features/delete_post/tests/delete_post_contract_test.php',
        2 => 'app/features/delete_post/tests/delete_post_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/delete_post',
    'action_class' => 'App\\Features\\DeletePost\\Action',
  ),
  'list_posts' => 
  array (
    'kind' => 'http',
    'description' => 'list_posts endpoint for blog-api.',
    'route' => 
    array (
      'method' => 'GET',
      'path' => '/posts',
    ),
    'input_schema' => 'app/features/list_posts/input.schema.json',
    'output_schema' => 'app/features/list_posts/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'list_posts.execute',
      ),
      'public' => false,
    ),
    'database' => 
    array (
      'reads' => 
      array (
      ),
      'writes' => 
      array (
      ),
      'transactions' => 'required',
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
      'bucket' => 'list_posts',
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
        0 => 'app/features/list_posts/tests/list_posts_auth_test.php',
        1 => 'app/features/list_posts/tests/list_posts_contract_test.php',
        2 => 'app/features/list_posts/tests/list_posts_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/list_posts',
    'action_class' => 'App\\Features\\ListPosts\\Action',
  ),
  'publish_post' => 
  array (
    'kind' => 'http',
    'description' => 'publish_post endpoint for blog-api.',
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
        0 => 'publish_post.execute',
      ),
      'public' => false,
    ),
    'database' => 
    array (
      'reads' => 
      array (
      ),
      'writes' => 
      array (
      ),
      'transactions' => 'required',
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
      'bucket' => 'publish_post',
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
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/publish_post',
    'action_class' => 'App\\Features\\PublishPost\\Action',
  ),
  'update_post' => 
  array (
    'kind' => 'http',
    'description' => 'update_post endpoint for blog-api.',
    'route' => 
    array (
      'method' => 'PUT',
      'path' => '/posts/{id}',
    ),
    'input_schema' => 'app/features/update_post/input.schema.json',
    'output_schema' => 'app/features/update_post/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'update_post.execute',
      ),
      'public' => false,
    ),
    'database' => 
    array (
      'reads' => 
      array (
      ),
      'writes' => 
      array (
      ),
      'transactions' => 'required',
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
      'bucket' => 'update_post',
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
        0 => 'app/features/update_post/tests/update_post_auth_test.php',
        1 => 'app/features/update_post/tests/update_post_contract_test.php',
        2 => 'app/features/update_post/tests/update_post_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/update_post',
    'action_class' => 'App\\Features\\UpdatePost\\Action',
  ),
  'view_post' => 
  array (
    'kind' => 'http',
    'description' => 'view_post endpoint for blog-api.',
    'route' => 
    array (
      'method' => 'GET',
      'path' => '/posts/{id}',
    ),
    'input_schema' => 'app/features/view_post/input.schema.json',
    'output_schema' => 'app/features/view_post/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'view_post.execute',
      ),
      'public' => false,
    ),
    'database' => 
    array (
      'reads' => 
      array (
      ),
      'writes' => 
      array (
      ),
      'transactions' => 'required',
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
      'bucket' => 'view_post',
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
        0 => 'app/features/view_post/tests/view_post_auth_test.php',
        1 => 'app/features/view_post/tests/view_post_contract_test.php',
        2 => 'app/features/view_post/tests/view_post_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/view_post',
    'action_class' => 'App\\Features\\ViewPost\\Action',
  ),
);
