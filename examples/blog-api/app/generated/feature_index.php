<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT DIRECTLY
 * Source: app/features/<feature>/feature.yaml
 * Regenerate with: forge generate indexes
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
    ),
    'events' => 
    array (
      'emit' => 
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
    ),
    'rate_limit' => 
    array (
      'strategy' => 'user',
      'bucket' => 'delete_post',
      'cost' => 1,
    ),
    'tests' => 
    array (
      'required' => 
      array (
        0 => 'contract',
        1 => 'feature',
        2 => 'auth',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk' => 'low',
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
    ),
    'events' => 
    array (
      'emit' => 
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
    ),
    'rate_limit' => 
    array (
      'strategy' => 'user',
      'bucket' => 'list_posts',
      'cost' => 1,
    ),
    'tests' => 
    array (
      'required' => 
      array (
        0 => 'contract',
        1 => 'feature',
        2 => 'auth',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk' => 'low',
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
    ),
    'events' => 
    array (
      'emit' => 
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
    ),
    'rate_limit' => 
    array (
      'strategy' => 'user',
      'bucket' => 'publish_post',
      'cost' => 1,
    ),
    'tests' => 
    array (
      'required' => 
      array (
        0 => 'contract',
        1 => 'feature',
        2 => 'auth',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk' => 'low',
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
    ),
    'events' => 
    array (
      'emit' => 
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
    ),
    'rate_limit' => 
    array (
      'strategy' => 'user',
      'bucket' => 'update_post',
      'cost' => 1,
    ),
    'tests' => 
    array (
      'required' => 
      array (
        0 => 'contract',
        1 => 'feature',
        2 => 'auth',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk' => 'low',
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
    ),
    'events' => 
    array (
      'emit' => 
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
    ),
    'rate_limit' => 
    array (
      'strategy' => 'user',
      'bucket' => 'view_post',
      'cost' => 1,
    ),
    'tests' => 
    array (
      'required' => 
      array (
        0 => 'contract',
        1 => 'feature',
        2 => 'auth',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/view_post',
    'action_class' => 'App\\Features\\ViewPost\\Action',
  ),
);
