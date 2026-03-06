<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT DIRECTLY
 * Source: app/features/<feature>/feature.yaml
 * Regenerate with: forge generate indexes
 */

return array (
  'current_user' => 
  array (
    'kind' => 'http',
    'description' => 'current_user endpoint for dashboard.',
    'route' => 
    array (
      'method' => 'GET',
      'path' => '/me',
    ),
    'input_schema' => 'app/features/current_user/input.schema.json',
    'output_schema' => 'app/features/current_user/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'current_user.execute',
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
      'bucket' => 'current_user',
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
    'base_path' => 'app/features/current_user',
    'action_class' => 'App\\Features\\CurrentUser\\Action',
  ),
  'list_notifications' => 
  array (
    'kind' => 'http',
    'description' => 'list_notifications endpoint for dashboard.',
    'route' => 
    array (
      'method' => 'GET',
      'path' => '/notifications',
    ),
    'input_schema' => 'app/features/list_notifications/input.schema.json',
    'output_schema' => 'app/features/list_notifications/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'list_notifications.execute',
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
      'bucket' => 'list_notifications',
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
    'base_path' => 'app/features/list_notifications',
    'action_class' => 'App\\Features\\ListNotifications\\Action',
  ),
  'login' => 
  array (
    'kind' => 'http',
    'description' => 'login endpoint for dashboard.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/login',
    ),
    'input_schema' => 'app/features/login/input.schema.json',
    'output_schema' => 'app/features/login/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'login.execute',
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
      'bucket' => 'login',
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
    'base_path' => 'app/features/login',
    'action_class' => 'App\\Features\\Login\\Action',
  ),
  'upload_avatar' => 
  array (
    'kind' => 'http',
    'description' => 'upload_avatar endpoint for dashboard.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/avatar',
    ),
    'input_schema' => 'app/features/upload_avatar/input.schema.json',
    'output_schema' => 'app/features/upload_avatar/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'upload_avatar.execute',
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
      'bucket' => 'upload_avatar',
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
    'base_path' => 'app/features/upload_avatar',
    'action_class' => 'App\\Features\\UploadAvatar\\Action',
  ),
);
