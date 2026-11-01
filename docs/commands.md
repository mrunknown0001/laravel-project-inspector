# Command reference

_Generated from the command definitions by `composer docs`; do not edit by hand._

Global options available on every command: `--help`, `--quiet`, `--silent`, `--verbose` (`-v`, `-vv`, `-vvv`), `--version`, `--ansi` / `--no-ansi`, `--no-interaction` (`-n`).

## Exit codes

| Code | Meaning |
|---:|---|
| 0 | Success. |
| 1 | Error: invalid input, not a Laravel project, unreadable path, refused output location, AI provider not configured or every AI task failed. |
| 2 | `--fail-on` was given and at least one finding is at or above that severity. |

## Commands

- [`ai`](#ai) - AI-assisted analysis of inspection results (explanations, remediation plans, documentation)
- [`ai:models`](#aimodels) - List the models a provider offers (calls the provider's model list endpoint)
- [`ai:providers`](#aiproviders) - List AI providers, their configuration status and (with --check) whether they are reachable
- [`architecture`](#architecture) - Summarise the application architecture: components, patterns, frontend stack and integrations
- [`config:init`](#configinit) - Create the inspector settings file from .env.example (never overwrites an existing file)
- [`config:show`](#configshow) - Show the inspector's configuration and where each value comes from (API keys masked)
- [`config:test`](#configtest) - Check an AI provider's configuration and send one tiny test request (no project data)
- [`dependencies`](#dependencies) - Analyze Composer dependencies: lock drift, PHP compatibility, advisories, abandoned and outdated packages
- [`environment`](#environment) - Show PHP, Laravel, tooling, frontend and driver information for a project
- [`inspect`](#inspect) - Run every analyzer against a Laravel project and print the report
- [`report`](#report) - Render a report from saved results, or from a fresh inspection when a path is given
- [`routes`](#routes) - List routes with their middleware, controllers and access control (read statically by default)
- [`security`](#security) - Run rule-based security checks: configuration, sensitive files, secrets, dangerous code, routes, dependencies

## ai

AI-assisted analysis of inspection results (explanations, remediation plans, documentation).

```text
larainspect ai [options] [--] [<path>]
```

| Argument | Description |
|---|---|
| `path` (optional) | Path to the Laravel project to inspect |

| Option | Description | Default |
|---|---|---|
| `--only=VALUE` (repeatable) | Run only these analyzers (comma-separated or repeated): environment, dependencies, routes, configuration, database, security, architecture, quality |  |
| `--exclude=VALUE` (repeatable) | Skip these analyzers (comma-separated or repeated) |  |
| `--use-artisan` | List routes with "php artisan route:list" (boots the project; asks for confirmation) |  |
| `--run-quality-tools[=VALUE]` | Run code-quality tools in report-only mode (all, or a list: phpstan,pint,php-cs-fixer,phpcs); asks for confirmation |  |
| `--format, -f=VALUE` | Output format: console, markdown, html, json | `console` |
| `--output, -o=VALUE` | Write the report to this file ("-" for standard output). Must be outside the inspected project |  |
| `--offline` | Skip checks that contact remote services (Packagist advisories and outdated packages) |  |
| `--no-save` | Do not write any files (no saved results and no audit log) |  |
| `--fail-on=VALUE` | Exit with code 2 if any finding is at or above this severity (critical, high, medium, low, info) |  |
| `--provider=VALUE` | AI provider: anthropic, openai, openrouter, notokenlimit |  |
| `--model=VALUE` | AI model (overrides &lt;PROVIDER&gt;_MODEL and AI_MODEL) |  |
| `--ai-task=VALUE` (repeatable) | AI tasks to run (comma-separated or repeated): explain-findings, architecture, dependencies, security-fixes, documentation, technical-debt, best-practices, all. Default: explain-findings, security-fixes, technical-debt |  |
| `--from=VALUE` | Use saved results instead of inspecting (default without a path: the latest saved results) |  |
| `--dry-run` | Print exactly what would be sent to the provider, and send nothing |  |

```text
Runs AI tasks over inspection results. The AI receives only the redacted
results (findings, facts, summaries), never source code or .env files.

  larainspect ai /path/to/project --provider=anthropic
  larainspect ai --from=reports/app-20261101-120000.json --ai-task=documentation
  larainspect ai ./app --provider=openrouter --model=anthropic/claude-opus-5.5 --ai-task=all
  larainspect ai ./app --dry-run     (review the prompts first; nothing is sent)

Tasks: explain-findings, architecture, dependencies, security-fixes,
documentation, technical-debt, best-practices (default: explain-findings,
security-fixes, technical-debt). Each task is one API request.
```

## ai:models

List the models a provider offers (calls the provider's model list endpoint).

```text
larainspect ai:models [options] [--] [<provider>]
```

| Argument | Description |
|---|---|
| `provider` (optional) | Provider name (default: AI_PROVIDER) |

| Option | Description | Default |
|---|---|---|
| `--filter=VALUE` | Only show model ids containing this text |  |

## ai:providers

List AI providers, their configuration status and (with --check) whether they are reachable.

```text
larainspect ai:providers [options]
```

| Option | Description | Default |
|---|---|---|
| `--check` | Call each configured provider's model list endpoint (network; sends no inspection data) |  |

## architecture

Summarise the application architecture: components, patterns, frontend stack and integrations.

```text
larainspect architecture [options] [--] <path>
```

| Argument | Description |
|---|---|
| `path` | Path to the Laravel project to inspect |

| Option | Description | Default |
|---|---|---|
| `--use-artisan` | List routes with "php artisan route:list" (boots the project; asks for confirmation) |  |
| `--format, -f=VALUE` | Output format: console, markdown, html, json | `console` |
| `--output, -o=VALUE` | Write the report to this file ("-" for standard output). Must be outside the inspected project |  |
| `--offline` | Skip checks that contact remote services (Packagist advisories and outdated packages) |  |
| `--no-save` | Do not write any files (no saved results and no audit log) |  |
| `--fail-on=VALUE` | Exit with code 2 if any finding is at or above this severity (critical, high, medium, low, info) |  |
| `--ai` | Add AI-assisted analysis (sends redacted inspection results to the configured provider) |  |
| `--no-ai` | Never run AI analysis, even if AI_ENABLED=true |  |
| `--provider=VALUE` | AI provider: anthropic, openai, openrouter, notokenlimit |  |
| `--model=VALUE` | AI model (overrides &lt;PROVIDER&gt;_MODEL and AI_MODEL) |  |
| `--ai-task=VALUE` (repeatable) | AI tasks to run (comma-separated or repeated): explain-findings, architecture, dependencies, security-fixes, documentation, technical-debt, best-practices, all. Default: explain-findings, security-fixes, technical-debt |  |

## config:init

Create the inspector settings file from .env.example (never overwrites an existing file).

```text
larainspect config:init [options]
```

| Option | Description | Default |
|---|---|---|
| `--user` | Create the per-user file (~/.config/larainspect/.env, %APPDATA%\larainspect\.env on Windows) instead of .env in the inspector directory |  |

## config:show

Show the inspector's configuration and where each value comes from (API keys masked).

```text
larainspect config:show
```

## config:test

Check an AI provider's configuration and send one tiny test request (no project data).

```text
larainspect config:test [options] [--] [<provider>]
```

| Argument | Description |
|---|---|
| `provider` (optional) | Provider name (default: AI_PROVIDER) |

| Option | Description | Default |
|---|---|---|
| `--model=VALUE` | Model to test |  |

## dependencies

Analyze Composer dependencies: lock drift, PHP compatibility, advisories, abandoned and outdated packages.

```text
larainspect dependencies [options] [--] <path>
```

| Argument | Description |
|---|---|
| `path` | Path to the Laravel project to inspect |

| Option | Description | Default |
|---|---|---|
| `--format, -f=VALUE` | Output format: console, markdown, html, json | `console` |
| `--output, -o=VALUE` | Write the report to this file ("-" for standard output). Must be outside the inspected project |  |
| `--offline` | Skip checks that contact remote services (Packagist advisories and outdated packages) |  |
| `--no-save` | Do not write any files (no saved results and no audit log) |  |
| `--fail-on=VALUE` | Exit with code 2 if any finding is at or above this severity (critical, high, medium, low, info) |  |
| `--ai` | Add AI-assisted analysis (sends redacted inspection results to the configured provider) |  |
| `--no-ai` | Never run AI analysis, even if AI_ENABLED=true |  |
| `--provider=VALUE` | AI provider: anthropic, openai, openrouter, notokenlimit |  |
| `--model=VALUE` | AI model (overrides &lt;PROVIDER&gt;_MODEL and AI_MODEL) |  |
| `--ai-task=VALUE` (repeatable) | AI tasks to run (comma-separated or repeated): explain-findings, architecture, dependencies, security-fixes, documentation, technical-debt, best-practices, all. Default: explain-findings, security-fixes, technical-debt |  |

## environment

Show PHP, Laravel, tooling, frontend and driver information for a project.

```text
larainspect environment [options] [--] <path>
```

| Argument | Description |
|---|---|
| `path` | Path to the Laravel project to inspect |

| Option | Description | Default |
|---|---|---|
| `--format, -f=VALUE` | Output format: console, markdown, html, json | `console` |
| `--output, -o=VALUE` | Write the report to this file ("-" for standard output). Must be outside the inspected project |  |
| `--offline` | Skip checks that contact remote services (Packagist advisories and outdated packages) |  |
| `--no-save` | Do not write any files (no saved results and no audit log) |  |
| `--fail-on=VALUE` | Exit with code 2 if any finding is at or above this severity (critical, high, medium, low, info) |  |
| `--ai` | Add AI-assisted analysis (sends redacted inspection results to the configured provider) |  |
| `--no-ai` | Never run AI analysis, even if AI_ENABLED=true |  |
| `--provider=VALUE` | AI provider: anthropic, openai, openrouter, notokenlimit |  |
| `--model=VALUE` | AI model (overrides &lt;PROVIDER&gt;_MODEL and AI_MODEL) |  |
| `--ai-task=VALUE` (repeatable) | AI tasks to run (comma-separated or repeated): explain-findings, architecture, dependencies, security-fixes, documentation, technical-debt, best-practices, all. Default: explain-findings, security-fixes, technical-debt |  |

## inspect

Run every analyzer against a Laravel project and print the report.

Aliases: `analyze`

```text
larainspect inspect [options] [--] <path>
```

| Argument | Description |
|---|---|
| `path` | Path to the Laravel project to inspect |

| Option | Description | Default |
|---|---|---|
| `--only=VALUE` (repeatable) | Run only these analyzers (comma-separated or repeated): environment, dependencies, routes, configuration, database, security, architecture, quality |  |
| `--exclude=VALUE` (repeatable) | Skip these analyzers (comma-separated or repeated) |  |
| `--use-artisan` | List routes with "php artisan route:list" (boots the project; asks for confirmation) |  |
| `--run-quality-tools[=VALUE]` | Run code-quality tools in report-only mode (all, or a list: phpstan,pint,php-cs-fixer,phpcs); asks for confirmation |  |
| `--format, -f=VALUE` | Output format: console, markdown, html, json | `console` |
| `--output, -o=VALUE` | Write the report to this file ("-" for standard output). Must be outside the inspected project |  |
| `--offline` | Skip checks that contact remote services (Packagist advisories and outdated packages) |  |
| `--no-save` | Do not write any files (no saved results and no audit log) |  |
| `--fail-on=VALUE` | Exit with code 2 if any finding is at or above this severity (critical, high, medium, low, info) |  |
| `--ai` | Add AI-assisted analysis (sends redacted inspection results to the configured provider) |  |
| `--no-ai` | Never run AI analysis, even if AI_ENABLED=true |  |
| `--provider=VALUE` | AI provider: anthropic, openai, openrouter, notokenlimit |  |
| `--model=VALUE` | AI model (overrides &lt;PROVIDER&gt;_MODEL and AI_MODEL) |  |
| `--ai-task=VALUE` (repeatable) | AI tasks to run (comma-separated or repeated): explain-findings, architecture, dependencies, security-fixes, documentation, technical-debt, best-practices, all. Default: explain-findings, security-fixes, technical-debt |  |

```text
Inspects a Laravel project without modifying it and prints a report.

  larainspect inspect /path/to/project
  larainspect inspect ./app --only=dependencies --offline
  larainspect inspect ./app --format=html --output=report.html
  larainspect inspect ./app --fail-on=high   (exit code 2 in CI when HIGH+ findings exist)

Results are saved as JSON in ./reports so they can be re-rendered later with
larainspect report. Use --no-save to write nothing.
```

## report

Render a report from saved results, or from a fresh inspection when a path is given.

```text
larainspect report [options] [--] [<path>]
```

| Argument | Description |
|---|---|
| `path` (optional) | Path to the Laravel project to inspect |

| Option | Description | Default |
|---|---|---|
| `--only=VALUE` (repeatable) | Run only these analyzers (comma-separated or repeated): environment, dependencies, routes, configuration, database, security, architecture, quality |  |
| `--exclude=VALUE` (repeatable) | Skip these analyzers (comma-separated or repeated) |  |
| `--use-artisan` | List routes with "php artisan route:list" (boots the project; asks for confirmation) |  |
| `--run-quality-tools[=VALUE]` | Run code-quality tools in report-only mode (all, or a list: phpstan,pint,php-cs-fixer,phpcs); asks for confirmation |  |
| `--format, -f=VALUE` | Output format: console, markdown, html, json | `markdown` |
| `--output, -o=VALUE` | Write the report to this file ("-" for standard output). Must be outside the inspected project |  |
| `--offline` | Skip checks that contact remote services (Packagist advisories and outdated packages) |  |
| `--no-save` | Do not write any files (no saved results and no audit log) |  |
| `--fail-on=VALUE` | Exit with code 2 if any finding is at or above this severity (critical, high, medium, low, info) |  |
| `--ai` | Add AI-assisted analysis (sends redacted inspection results to the configured provider) |  |
| `--no-ai` | Never run AI analysis, even if AI_ENABLED=true |  |
| `--provider=VALUE` | AI provider: anthropic, openai, openrouter, notokenlimit |  |
| `--model=VALUE` | AI model (overrides &lt;PROVIDER&gt;_MODEL and AI_MODEL) |  |
| `--ai-task=VALUE` (repeatable) | AI tasks to run (comma-separated or repeated): explain-findings, architecture, dependencies, security-fixes, documentation, technical-debt, best-practices, all. Default: explain-findings, security-fixes, technical-debt |  |
| `--from=VALUE` | Saved JSON results to render (default: the most recent file in the output directory) |  |

```text
Renders a report.

Without a path, the most recent saved results in ./reports are rendered:
  larainspect report --format=html
  larainspect report --from=reports/myapp-20261101-120000.json --format=markdown

With a path, the project is inspected first (same options as "inspect"):
  larainspect report /path/to/project --format=markdown --output=docs/inspection.md
```

## routes

List routes with their middleware, controllers and access control (read statically by default).

```text
larainspect routes [options] [--] <path>
```

| Argument | Description |
|---|---|
| `path` | Path to the Laravel project to inspect |

| Option | Description | Default |
|---|---|---|
| `--use-artisan` | List routes with "php artisan route:list" (boots the project; asks for confirmation) |  |
| `--format, -f=VALUE` | Output format: console, markdown, html, json | `console` |
| `--output, -o=VALUE` | Write the report to this file ("-" for standard output). Must be outside the inspected project |  |
| `--offline` | Skip checks that contact remote services (Packagist advisories and outdated packages) |  |
| `--no-save` | Do not write any files (no saved results and no audit log) |  |
| `--fail-on=VALUE` | Exit with code 2 if any finding is at or above this severity (critical, high, medium, low, info) |  |
| `--ai` | Add AI-assisted analysis (sends redacted inspection results to the configured provider) |  |
| `--no-ai` | Never run AI analysis, even if AI_ENABLED=true |  |
| `--provider=VALUE` | AI provider: anthropic, openai, openrouter, notokenlimit |  |
| `--model=VALUE` | AI model (overrides &lt;PROVIDER&gt;_MODEL and AI_MODEL) |  |
| `--ai-task=VALUE` (repeatable) | AI tasks to run (comma-separated or repeated): explain-findings, architecture, dependencies, security-fixes, documentation, technical-debt, best-practices, all. Default: explain-findings, security-fixes, technical-debt |  |

## security

Run rule-based security checks: configuration, sensitive files, secrets, dangerous code, routes, dependencies.

```text
larainspect security [options] [--] <path>
```

| Argument | Description |
|---|---|
| `path` | Path to the Laravel project to inspect |

| Option | Description | Default |
|---|---|---|
| `--use-artisan` | List routes with "php artisan route:list" (boots the project; asks for confirmation) |  |
| `--format, -f=VALUE` | Output format: console, markdown, html, json | `console` |
| `--output, -o=VALUE` | Write the report to this file ("-" for standard output). Must be outside the inspected project |  |
| `--offline` | Skip checks that contact remote services (Packagist advisories and outdated packages) |  |
| `--no-save` | Do not write any files (no saved results and no audit log) |  |
| `--fail-on=VALUE` | Exit with code 2 if any finding is at or above this severity (critical, high, medium, low, info) |  |
| `--ai` | Add AI-assisted analysis (sends redacted inspection results to the configured provider) |  |
| `--no-ai` | Never run AI analysis, even if AI_ENABLED=true |  |
| `--provider=VALUE` | AI provider: anthropic, openai, openrouter, notokenlimit |  |
| `--model=VALUE` | AI model (overrides &lt;PROVIDER&gt;_MODEL and AI_MODEL) |  |
| `--ai-task=VALUE` (repeatable) | AI tasks to run (comma-separated or repeated): explain-findings, architecture, dependencies, security-fixes, documentation, technical-debt, best-practices, all. Default: explain-findings, security-fixes, technical-debt |  |
