# Dashboard Example

`examples/dashboard` is a supplemental authenticated UI/API slice. It is useful when you want a compact example of login, profile, notifications, and media-upload style feature folders.

What it teaches:

- authenticated route structure
- profile-style read endpoints
- upload-oriented feature organization
- execution-plan inspection for auth-heavy routes

How to use it:

1. Copy `examples/dashboard/app/features/*` into a Foundry app's `app/features/` tree.
2. Optionally copy `examples/dashboard/public/index.php`.
3. From that generated app, run:

```bash
foundry compile graph --json
foundry inspect graph --command="POST /login" --json
foundry inspect graph --feature=upload_avatar --json
foundry doctor --feature=login --json
foundry verify graph --json
foundry verify pipeline --json
```
