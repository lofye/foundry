# Reference Blog Commands

Bootstrap a fresh app from this repository:

```bash
php bin/foundry new tmp/reference-blog --starter=standard --json
cd tmp/reference-blog
composer require spatie/laravel-feed
```

Then hand the generated app plus `examples/reference-blog/llm-prompt.md` to your LLM and ask it to apply the changes in place.

After the prompt-driven edits are done, verify the result:

```bash
foundry compile graph --json
foundry inspect graph --feature=publish_post --json
foundry inspect graph --feature=admin_login --json
foundry doctor --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
php vendor/bin/phpunit
```

Minimum expected outcome:

- public blog index and post detail routes
- admin login plus protected post-create and post-publish flows
- RSS generation wired through `spatie/laravel-feed`
- example content imported from this kit
