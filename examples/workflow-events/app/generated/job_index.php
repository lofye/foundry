<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
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
    'feature' => 'publish_story',
  ),
);
