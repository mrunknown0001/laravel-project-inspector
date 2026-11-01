# Changelog

## 0.1.0 - 2026-11-01

First release.

- **Inspection:** project detection for Laravel 8 and later (best effort for older versions), and
  eight analyzers: environment, dependencies, routes, configuration, database, security,
  architecture and code quality.
- **Reports:** terminal, Markdown, HTML (self-contained) and JSON; saved results can be re-rendered
  with `report`; `--fail-on` for CI.
- **Safety:** read-only file access confined to the project, static parsing instead of executing
  project code, `.env` allowlist and secret redaction, allowlisted shell-free commands with
  timeouts, Composer without plugins or scripts, an audit log of every external operation, and
  interactive confirmation for anything that runs project code (`--use-artisan`,
  `--run-quality-tools`).
- **AI (optional):** Anthropic, OpenAI, OpenRouter and NoTokenLimit adapters behind one interface;
  seven tasks over redacted inspection results; `--dry-run` to review prompts; configuration
  commands (`config:show`, `config:init`, `config:test`, `ai:providers`, `ai:models`).
