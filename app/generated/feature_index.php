<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT DIRECTLY
 * Source: app/features/<feature>/feature.yaml
 * Regenerate with: foundry generate indexes
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
    ),
    'events' => 
    array (
      'emit' => 
      array (
        0 => 'post.created',
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
    ),
    'rate_limit' => 
    array (
      'strategy' => 'user',
      'bucket' => 'post_create',
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
      'risk' => 'medium',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/publish_post',
    'action_class' => 'App\\Features\\PublishPost\\Action',
  ),
);
