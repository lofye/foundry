<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: php vendor/bin/foundry compile graph
 */
return array (
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
    'feature' => 'publish_post',
  ),
);
