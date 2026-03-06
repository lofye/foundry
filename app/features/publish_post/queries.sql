-- name: find_user_by_id
SELECT id, email, role
FROM users
WHERE id = :id
LIMIT 1;

-- name: insert_post
INSERT INTO posts (
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
);
