<?php
declare(strict_types=1);

/**
 * GENERATED FILE - DO NOT EDIT
 * Built by Foundry semantic compiler.
 * Regenerate with: foundry compile graph
 */
return array (
  'publish_post:find_user_by_id' => 
  array (
    'feature' => 'publish_post',
    'name' => 'find_user_by_id',
    'sql' => 'SELECT id, email, role
FROM users
WHERE id = :id
LIMIT 1;',
    'placeholders' => 
    array (
      0 => 'id',
    ),
  ),
  'publish_post:insert_post' => 
  array (
    'feature' => 'publish_post',
    'name' => 'insert_post',
    'sql' => 'INSERT INTO posts (
  id,
  author_id,
  title,
  slug,
  body_markdown,
  status,
  publish_at,
  created_at
) VALUES (
  :id,
  :author_id,
  :title,
  :slug,
  :body_markdown,
  :status,
  :publish_at,
  :created_at
);',
    'placeholders' => 
    array (
      0 => 'author_id',
      1 => 'body_markdown',
      2 => 'created_at',
      3 => 'id',
      4 => 'publish_at',
      5 => 'slug',
      6 => 'status',
      7 => 'title',
    ),
  ),
);
