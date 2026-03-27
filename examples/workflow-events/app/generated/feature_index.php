<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'publish_story' => 
  array (
    'kind' => 'http',
    'description' => 'Publish a reviewed story and emit the final public event.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/editorial/stories/publish',
    ),
    'input_schema' => 'app/features/publish_story/input.schema.json',
    'output_schema' => 'app/features/publish_story/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'editorial.publish',
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
        0 => 'stories:list',
      ),
      'entries' => 
      array (
        'stories:list' => 
        array (
          'kind' => 'computed',
          'ttl_seconds' => 120,
          'invalidated_by' => 
          array (
            0 => 'publish_story',
          ),
        ),
      ),
    ),
    'events' => 
    array (
      'emit' => 
      array (
        0 => 'story.published',
      ),
      'emit_definitions' => 
      array (
        'story.published' => 
        array (
          'type' => 'object',
          'additionalProperties' => false,
          'required' => 
          array (
            0 => 'story_id',
          ),
          'properties' => 
          array (
            'story_id' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
      ),
      'subscribe' => 
      array (
        0 => 'story.review_requested',
      ),
    ),
    'jobs' => 
    array (
      'dispatch' => 
      array (
        0 => 'refresh_story_feed',
      ),
      'definitions' => 
      array (
        'refresh_story_feed' => 
        array (
          'input_schema' => 
          array (
            'type' => 'object',
            'additionalProperties' => false,
            'required' => 
            array (
              0 => 'story_id',
            ),
            'properties' => 
            array (
              'story_id' => 
              array (
                'type' => 'string',
              ),
            ),
          ),
          'queue' => 'feeds',
          'retry' => 
          array (
          ),
          'timeout_seconds' => 30,
          'idempotency_key' => NULL,
        ),
      ),
    ),
    'rate_limit' => 
    array (
      'bucket' => 'publish_story',
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
        0 => 'app/features/publish_story/tests/publish_story_auth_test.php',
        1 => 'app/features/publish_story/tests/publish_story_contract_test.php',
        2 => 'app/features/publish_story/tests/publish_story_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'medium',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/publish_story',
    'action_class' => 'App\\Features\\PublishStory\\Action',
  ),
  'review_story' => 
  array (
    'kind' => 'http',
    'description' => 'Request review on a submitted story and emit the handoff event.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/editorial/stories/review',
    ),
    'input_schema' => 'app/features/review_story/input.schema.json',
    'output_schema' => 'app/features/review_story/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'editorial.review',
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
        0 => 'story.review_requested',
      ),
      'emit_definitions' => 
      array (
        'story.review_requested' => 
        array (
          'type' => 'object',
          'additionalProperties' => false,
          'required' => 
          array (
            0 => 'story_id',
          ),
          'properties' => 
          array (
            'story_id' => 
            array (
              'type' => 'string',
            ),
          ),
        ),
      ),
      'subscribe' => 
      array (
        0 => 'story.submitted',
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
      'bucket' => 'review_story',
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
        0 => 'app/features/review_story/tests/review_story_auth_test.php',
        1 => 'app/features/review_story/tests/review_story_contract_test.php',
        2 => 'app/features/review_story/tests/review_story_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/review_story',
    'action_class' => 'App\\Features\\ReviewStory\\Action',
  ),
  'submit_story' => 
  array (
    'kind' => 'http',
    'description' => 'Create a draft story and emit the initial editorial event.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/editorial/stories',
    ),
    'input_schema' => 'app/features/submit_story/input.schema.json',
    'output_schema' => 'app/features/submit_story/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'editorial.submit',
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
        0 => 'stories:list',
      ),
      'entries' => 
      array (
        'stories:list' => 
        array (
          'kind' => 'computed',
          'ttl_seconds' => 120,
          'invalidated_by' => 
          array (
            0 => 'publish_story',
            1 => 'submit_story',
          ),
        ),
      ),
    ),
    'events' => 
    array (
      'emit' => 
      array (
        0 => 'story.submitted',
      ),
      'emit_definitions' => 
      array (
        'story.submitted' => 
        array (
          'type' => 'object',
          'additionalProperties' => false,
          'required' => 
          array (
            0 => 'title',
          ),
          'properties' => 
          array (
            'title' => 
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
      ),
      'definitions' => 
      array (
      ),
    ),
    'rate_limit' => 
    array (
      'bucket' => 'submit_story',
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
        0 => 'app/features/submit_story/tests/submit_story_auth_test.php',
        1 => 'app/features/submit_story/tests/submit_story_contract_test.php',
        2 => 'app/features/submit_story/tests/submit_story_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/submit_story',
    'action_class' => 'App\\Features\\SubmitStory\\Action',
  ),
);
