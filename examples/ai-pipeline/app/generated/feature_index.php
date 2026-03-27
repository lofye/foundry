<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'classify_document' => 
  array (
    'kind' => 'http',
    'description' => 'classify_document endpoint for ai-pipeline.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/documents/{id}/classify',
    ),
    'input_schema' => 'app/features/classify_document/input.schema.json',
    'output_schema' => 'app/features/classify_document/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'classify_document.execute',
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
      'bucket' => 'classify_document',
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
        0 => 'app/features/classify_document/tests/classify_document_auth_test.php',
        1 => 'app/features/classify_document/tests/classify_document_contract_test.php',
        2 => 'app/features/classify_document/tests/classify_document_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/classify_document',
    'action_class' => 'App\\Features\\ClassifyDocument\\Action',
  ),
  'extract_summary' => 
  array (
    'kind' => 'http',
    'description' => 'extract_summary endpoint for ai-pipeline.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/documents/{id}/summary',
    ),
    'input_schema' => 'app/features/extract_summary/input.schema.json',
    'output_schema' => 'app/features/extract_summary/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'extract_summary.execute',
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
      'bucket' => 'extract_summary',
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
        0 => 'app/features/extract_summary/tests/extract_summary_auth_test.php',
        1 => 'app/features/extract_summary/tests/extract_summary_contract_test.php',
        2 => 'app/features/extract_summary/tests/extract_summary_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/extract_summary',
    'action_class' => 'App\\Features\\ExtractSummary\\Action',
  ),
  'fetch_ai_result' => 
  array (
    'kind' => 'http',
    'description' => 'fetch_ai_result endpoint for ai-pipeline.',
    'route' => 
    array (
      'method' => 'GET',
      'path' => '/documents/{id}/ai-result',
    ),
    'input_schema' => 'app/features/fetch_ai_result/input.schema.json',
    'output_schema' => 'app/features/fetch_ai_result/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'fetch_ai_result.execute',
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
      'bucket' => 'fetch_ai_result',
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
        0 => 'app/features/fetch_ai_result/tests/fetch_ai_result_auth_test.php',
        1 => 'app/features/fetch_ai_result/tests/fetch_ai_result_contract_test.php',
        2 => 'app/features/fetch_ai_result/tests/fetch_ai_result_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/fetch_ai_result',
    'action_class' => 'App\\Features\\FetchAiResult\\Action',
  ),
  'queue_ai_summary_job' => 
  array (
    'kind' => 'http',
    'description' => 'queue_ai_summary_job endpoint for ai-pipeline.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/documents/{id}/queue-summary',
    ),
    'input_schema' => 'app/features/queue_ai_summary_job/input.schema.json',
    'output_schema' => 'app/features/queue_ai_summary_job/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'queue_ai_summary_job.execute',
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
      'bucket' => 'queue_ai_summary_job',
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
        0 => 'app/features/queue_ai_summary_job/tests/queue_ai_summary_job_auth_test.php',
        1 => 'app/features/queue_ai_summary_job/tests/queue_ai_summary_job_contract_test.php',
        2 => 'app/features/queue_ai_summary_job/tests/queue_ai_summary_job_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/queue_ai_summary_job',
    'action_class' => 'App\\Features\\QueueAiSummaryJob\\Action',
  ),
  'submit_document' => 
  array (
    'kind' => 'http',
    'description' => 'submit_document endpoint for ai-pipeline.',
    'route' => 
    array (
      'method' => 'POST',
      'path' => '/documents',
    ),
    'input_schema' => 'app/features/submit_document/input.schema.json',
    'output_schema' => 'app/features/submit_document/output.schema.json',
    'auth' => 
    array (
      'required' => true,
      'strategies' => 
      array (
        0 => 'bearer',
      ),
      'permissions' => 
      array (
        0 => 'submit_document.execute',
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
      'bucket' => 'submit_document',
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
        0 => 'app/features/submit_document/tests/submit_document_auth_test.php',
        1 => 'app/features/submit_document/tests/submit_document_contract_test.php',
        2 => 'app/features/submit_document/tests/submit_document_feature_test.php',
      ),
    ),
    'llm' => 
    array (
      'editable' => true,
      'risk_level' => 'low',
      'notes_file' => 'prompts.md',
    ),
    'base_path' => 'app/features/submit_document',
    'action_class' => 'App\\Features\\SubmitDocument\\Action',
  ),
);
