# App Scaffolding Examples

This folder provides minimal app-scaffolding source-of-truth definition examples.

Copy these definition files into a Foundry app's `app/definitions/*` tree, then run the commands below from that app with `foundry ...`.

## A. Starter
- `starter/server-rendered.starter.yaml`

## B. Resource CRUD
- `blog/posts.resource.yaml`
- `listing/posts.list.yaml`

## C. Admin
- `admin/posts.admin.yaml`

## D. Uploads
- `uploads/avatar.uploads.yaml`

Use these as deterministic inputs for:
- `foundry generate starter server-rendered --json`
- `foundry generate starter api --json`
- `foundry generate resource posts --definition=app/definitions/resources/posts.resource.yaml --json`
- `foundry inspect resource posts --json`
- `foundry generate admin-resource posts --definition=app/definitions/admin/posts.admin.yaml --json`
- `foundry generate uploads avatar --json`

`listing/posts.list.yaml` is the companion listing definition used by the resource and admin projections after generation.
