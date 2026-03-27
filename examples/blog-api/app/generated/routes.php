<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'DELETE /posts/{id}' => 
  array (
    'feature' => 'delete_post',
    'kind' => 'http',
    'input_schema' => 'app/features/delete_post/input.schema.json',
    'output_schema' => 'app/features/delete_post/output.schema.json',
  ),
  'GET /posts' => 
  array (
    'feature' => 'list_posts',
    'kind' => 'http',
    'input_schema' => 'app/features/list_posts/input.schema.json',
    'output_schema' => 'app/features/list_posts/output.schema.json',
  ),
  'GET /posts/{id}' => 
  array (
    'feature' => 'view_post',
    'kind' => 'http',
    'input_schema' => 'app/features/view_post/input.schema.json',
    'output_schema' => 'app/features/view_post/output.schema.json',
  ),
  'POST /posts' => 
  array (
    'feature' => 'publish_post',
    'kind' => 'http',
    'input_schema' => 'app/features/publish_post/input.schema.json',
    'output_schema' => 'app/features/publish_post/output.schema.json',
  ),
  'PUT /posts/{id}' => 
  array (
    'feature' => 'update_post',
    'kind' => 'http',
    'input_schema' => 'app/features/update_post/input.schema.json',
    'output_schema' => 'app/features/update_post/output.schema.json',
  ),
);
