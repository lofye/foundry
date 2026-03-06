<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT DIRECTLY
 * Source: app/features/<feature>/feature.yaml
 * Regenerate with: forge generate indexes
 */

return array (
  'posts:list' => 
  array (
    'feature' => 'publish_post',
    'kind' => 'computed',
    'ttl_seconds' => 300,
    'invalidated_by' => 
    array (
      0 => 'publish_post',
    ),
  ),
);
