<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
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
      'bucket' => 'current_user',
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
        0 => 'app/features/current_user/tests/current_user_auth_test.php',
        1 => 'app/features/current_user/tests/current_user_contract_test.php',
        2 => 'app/features/current_user/tests/current_user_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
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
      'bucket' => 'list_notifications',
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
        0 => 'app/features/list_notifications/tests/list_notifications_auth_test.php',
        1 => 'app/features/list_notifications/tests/list_notifications_contract_test.php',
        2 => 'app/features/list_notifications/tests/list_notifications_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
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
      'bucket' => 'login',
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
        0 => 'app/features/login/tests/login_auth_test.php',
        1 => 'app/features/login/tests/login_contract_test.php',
        2 => 'app/features/login/tests/login_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
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
      'bucket' => 'upload_avatar',
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
        0 => 'app/features/upload_avatar/tests/upload_avatar_auth_test.php',
        1 => 'app/features/upload_avatar/tests/upload_avatar_contract_test.php',
        2 => 'app/features/upload_avatar/tests/upload_avatar_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/upload_avatar',
    'action_class' => 'App\\Features\\UploadAvatar\\Action',
  ),
);
