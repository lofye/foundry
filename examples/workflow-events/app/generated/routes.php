<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'POST /editorial/stories' => 
  array (
    'feature' => 'submit_story',
    'kind' => 'http',
    'input_schema' => 'app/features/submit_story/input.schema.json',
    'output_schema' => 'app/features/submit_story/output.schema.json',
  ),
  'POST /editorial/stories/publish' => 
  array (
    'feature' => 'publish_story',
    'kind' => 'http',
    'input_schema' => 'app/features/publish_story/input.schema.json',
    'output_schema' => 'app/features/publish_story/output.schema.json',
  ),
  'POST /editorial/stories/review' => 
  array (
    'feature' => 'review_story',
    'kind' => 'http',
    'input_schema' => 'app/features/review_story/input.schema.json',
    'output_schema' => 'app/features/review_story/output.schema.json',
  ),
);
