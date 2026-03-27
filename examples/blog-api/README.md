# API-First Example

`examples/blog-api` is the official API-first example. It keeps the app small while still showing a realistic route-per-feature layout for a CRUD-style HTTP surface.

What it teaches:

- one feature folder per endpoint
- manifest, schema, permissions, cache, events, and test file placement
- graph inspection by route or feature
- doctor and verify loops for an API-heavy app

How to use it:

1. Copy `examples/blog-api/app/features/*` into a Foundry app's `app/features/` tree.
2. Copy `examples/blog-api/public/index.php` into the app if you want the same tiny platform entrypoint.
3. From that generated app, run:

```bash
foundry compile graph --json
foundry inspect graph --command="GET /posts" --json
foundry inspect graph --feature=publish_post --json
foundry doctor --feature=list_posts --json
foundry verify graph --json
foundry verify contracts --json
```

Read these folders first:

- `app/features/list_posts`
- `app/features/view_post`
- `app/features/publish_post`
- `app/features/update_post`
- `app/features/delete_post`
