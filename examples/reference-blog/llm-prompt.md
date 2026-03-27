# Reference Blog Prompt

Build a Foundry blog application in the current workspace.

Requirements:

- keep `app/features/*` as the source of truth
- use `version: 2` feature manifests
- create public routes for blog index, post detail, RSS feed, and about page
- create protected routes for admin login, dashboard, create post, update post, and publish post
- keep schemas, prompts, and tests colocated in each feature folder
- add a workflow or equivalent editorial flow for draft to published content
- integrate RSS generation using the installed `spatie/laravel-feed` Composer package
- run and fix `foundry compile graph --json`
- run and fix `foundry doctor --json`
- run and fix `foundry verify graph --json`
- run and fix `foundry verify pipeline --json`
- run and fix `foundry verify contracts --json`
- run and fix `php vendor/bin/phpunit`
- keep all new code above 90% automated test coverage

Content requirements:

- use `examples/reference-blog/content/about.md` for the about page
- use `examples/reference-blog/content/welcome-post.md` as the first seeded post
- use `examples/reference-blog/content/editorial-notes.md` as the editorial policy and implementation constraint summary

Architecture expectations:

- make doctor and graph inspection useful by keeping event names, permissions, and routes explicit
- prefer small feature folders over hidden shared runtime logic
- ensure the RSS flow is visible in either graph inspection, jobs, events, or workflow metadata
- keep admin authentication and authorization explicit in manifests and tests
