<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'GET /me' => 
  array (
    'feature' => 'current_user',
    'kind' => 'http',
    'input_schema' => 'app/features/current_user/input.schema.json',
    'output_schema' => 'app/features/current_user/output.schema.json',
  ),
  'GET /notifications' => 
  array (
    'feature' => 'list_notifications',
    'kind' => 'http',
    'input_schema' => 'app/features/list_notifications/input.schema.json',
    'output_schema' => 'app/features/list_notifications/output.schema.json',
  ),
  'POST /avatar' => 
  array (
    'feature' => 'upload_avatar',
    'kind' => 'http',
    'input_schema' => 'app/features/upload_avatar/input.schema.json',
    'output_schema' => 'app/features/upload_avatar/output.schema.json',
  ),
  'POST /login' => 
  array (
    'feature' => 'login',
    'kind' => 'http',
    'input_schema' => 'app/features/login/input.schema.json',
    'output_schema' => 'app/features/login/output.schema.json',
  ),
);
