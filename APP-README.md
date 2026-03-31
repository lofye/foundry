# {{DISPLAY_NAME}}

This Foundry project was scaffolded in `{{STARTER_LABEL}}` mode.

{{STARTER_SUMMARY}}

## Working With LLMs

Start with `AGENTS.md`. It defines the repo-local workflow and command rules for AI assistants working in this app.

## First Run

Foundry scaffolds a project-local `foundry` launcher. If your shell does not resolve current-directory executables, use `./foundry ...` instead.

```bash
composer install
foundry help inspect
foundry help verify
foundry compile graph --json
foundry inspect graph --json
foundry inspect pipeline --json
foundry doctor --json
foundry generate docs --format=markdown --json
foundry generate inspect-ui --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
php vendor/bin/phpunit -c phpunit.xml.dist
php -S 127.0.0.1:8000 public/index.php
```

## Starter Routes

{{ROUTE_SUMMARY}}

## Inspectability

- Generated graph docs: `docs/generated`
- Generated inspect UI: `docs/inspect-ui`
- Source definition example: `app/definitions/inspect-ui/dev.inspect-ui.yaml`
- {{AUTH_HINT}}

## Packs

Foundry can also load deterministic packs for extension work, either from disk or from the optional hosted registry.

- discover remote packs with `foundry pack search <query> --json`
- install from the hosted registry with `foundry pack install vendor/pack --json`
- install and inspect local packs with `foundry pack list --json` and `foundry inspect packs --json`
- active pack versions are tracked in `.foundry/packs/installed.json`
- hosted registry lookups are cached at `.foundry/cache/registry.json`
- installed pack files live under `.foundry/packs/*/*/*` and should be replaced from source rather than hand-edited
