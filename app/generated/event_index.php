<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'emit' => 
  array (
    'post.created' => 
    array (
      'feature' => 'publish_post',
      'schema' => 
      array (
        'type' => 'object',
        'additionalProperties' => false,
        'required' => 
        array (
          0 => 'post_id',
          1 => 'author_id',
          2 => 'status',
        ),
        'properties' => 
        array (
          'post_id' => 
          array (
            'type' => 'string',
          ),
          'author_id' => 
          array (
            'type' => 'string',
          ),
          'status' => 
          array (
            'type' => 'string',
          ),
        ),
      ),
    ),
  ),
  'subscribe' => 
  array (
  ),
);
