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
    'story.published' => 
    array (
      'feature' => 'publish_story',
      'schema' => 
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
    'story.review_requested' => 
    array (
      'feature' => 'review_story',
      'schema' => 
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
    'story.submitted' => 
    array (
      'feature' => 'submit_story',
      'schema' => 
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
  ),
  'subscribe' => 
  array (
    'story.review_requested' => 
    array (
      0 => 'publish_story',
    ),
    'story.submitted' => 
    array (
      0 => 'review_story',
    ),
  ),
);
