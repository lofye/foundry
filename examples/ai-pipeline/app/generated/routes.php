<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'GET /documents/{id}/ai-result' => 
  array (
    'feature' => 'fetch_ai_result',
    'kind' => 'http',
    'input_schema' => 'app/features/fetch_ai_result/input.schema.json',
    'output_schema' => 'app/features/fetch_ai_result/output.schema.json',
  ),
  'POST /documents' => 
  array (
    'feature' => 'submit_document',
    'kind' => 'http',
    'input_schema' => 'app/features/submit_document/input.schema.json',
    'output_schema' => 'app/features/submit_document/output.schema.json',
  ),
  'POST /documents/{id}/classify' => 
  array (
    'feature' => 'classify_document',
    'kind' => 'http',
    'input_schema' => 'app/features/classify_document/input.schema.json',
    'output_schema' => 'app/features/classify_document/output.schema.json',
  ),
  'POST /documents/{id}/queue-summary' => 
  array (
    'feature' => 'queue_ai_summary_job',
    'kind' => 'http',
    'input_schema' => 'app/features/queue_ai_summary_job/input.schema.json',
    'output_schema' => 'app/features/queue_ai_summary_job/output.schema.json',
  ),
  'POST /documents/{id}/summary' => 
  array (
    'feature' => 'extract_summary',
    'kind' => 'http',
    'input_schema' => 'app/features/extract_summary/input.schema.json',
    'output_schema' => 'app/features/extract_summary/output.schema.json',
  ),
);
