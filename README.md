# Laravel Project Inspector

A standalone, read-only CLI that inspects existing Laravel projects and produces technical reports:
stack and versions, dependencies, routes, configuration, database schema, security risks,
architecture and code quality - with optional AI-assisted explanations.

It runs from its own directory and never needs to be installed in, or modify, the project it
inspects.

```bash
larainspect inspect ~/projects/shop                      # terminal report
larainspect inspect ~/projects/shop --format=html        # self-contained HTML file in ./reports
larainspect security ~/projects/shop --fail-on=high      # CI gate: exit code 2 on HIGH/CRITICAL
larainspect ai ~/projects/shop --provider=anthropic      # AI remediation plan and explanations
```

## Documentation

| | |
|---|---|
| [Installation](docs/installation.md) | Linux, macOS, Windows, WSL, Composer global install, upgrading, troubleshooting. |
| [Command reference](docs/commands.md) | Every command and option (generated from the code). |
| [AI providers](docs/ai-providers.md) | Anthropic, OpenAI, OpenRouter and NoTokenLimit setup; what is sent. |
| [Security and privacy](docs/security-and-privacy.md) | What is read, what runs, what leaves your machine, and the limits. |
| [Extending](docs/extending.md) | Adding analyzers, security rules, AI providers, AI tasks and report formats. |
| [Example reports](docs/examples) | Markdown, HTML and JSON reports generated from the test projects. |
| [Changelog](CHANGELOG.md) | |

## Safety guarantees

- **The inspected project is never modified.** File access goes through a read-only reader confined
  to the project root (path traversal and escaping symlinks are rejected). Reports and logs are
  refused if they would land inside the project.
- **Project code is never executed by default.** Route files, config files, migrations and classes
  are parsed as syntax trees, never included. `artisan` and the project's own tools only run when
  you opt in (`--use-artisan`, `--run-quality-tools`) **and** confirm a prompt that shows the exact
  command and what it may change. Non-interactive sessions (CI, `-n`) always refuse.
- **The database is never connected to.** Schema information comes from migration files.
- **Secrets stay local.** Only an allowlist of non-sensitive `.env` keys is read (e.g. `APP_ENV`,
  `DB_CONNECTION`). Every report passes through a redactor that removes `.env` secret values, API
  tokens, private keys and URL credentials; secret findings show a masked prefix only.
- **External commands are restricted.** Only allowlisted executables run, without a shell, with
  timeouts. Composer always runs with `--locked --no-plugins --no-scripts` and is never asked to
  install or update. Every command is recorded in `reports/audit.log` and in the report.
- **Network use is limited.** Only `composer audit`/`outdated` contact Packagist (package names and
  versions); `--offline` disables them. There is no telemetry.
- **AI is off unless you ask for it,** and receives only redacted inspection results - never source
  code, `.env` files, absolute paths or the audit log. `larainspect ai --dry-run` prints exactly
  what would be sent. AI output is a separate, labelled section and never adds findings.

Details and limits: [docs/security-and-privacy.md](docs/security-and-privacy.md).

## Quick start

Requirements: PHP 8.3+ and Composer 2. Full instructions, including Windows:
[docs/installation.md](docs/installation.md).

```bash
git clone <repository-url> ~/tools/laravel-project-inspector
cd ~/tools/laravel-project-inspector
composer install --no-dev
ln -s "$PWD/bin/larainspect" ~/.local/bin/larainspect     # Windows: add bin\ to PATH, use larainspect.bat

cd ~                                                       # run from outside the project
larainspect inspect ~/projects/shop
```

Results are saved as JSON in `./reports` of the directory you run from (with the audit log), so
they can be re-rendered without inspecting again:

```bash
larainspect report --format=markdown                       # latest saved results
larainspect report --from=reports/shop-20261101-120000.json --format=html
```

## Commands

| Command | Description |
|---|---|
| `inspect <path>` (alias `analyze`) | Run every analyzer, or a selection with `--only` / `--exclude`. |
| `environment <path>` | Laravel and PHP versions and support status, tooling, frontend stack, infrastructure drivers. |
| `dependencies <path>` | Lock drift, PHP compatibility, advisories, abandoned and outdated packages, insecure Composer settings. |
| `routes <path>` | Route list with middleware, controllers and access level; routes that need review. |
| `security <path>` | Rule-based security checks. |
| `architecture <path>` | Component inventory, patterns, frontend stack, integrations and an overview. |
| `report [path]` | Render saved results, or inspect `path` first. Defaults to Markdown. |
| `ai [path]` | AI-assisted analysis of a fresh inspection or of saved results; `--dry-run` sends nothing. |
| `ai:providers`, `ai:models` | Provider status (keys masked) and available models. |
| `config:show`, `config:init`, `config:test` | Show settings and their source, create the settings file, test a provider. |

Common options: `--format=console|markdown|html|json`, `--output=<file>|-`, `--offline`,
`--no-save`, `--fail-on=<severity>`, `--only=` / `--exclude=`, `--ai` / `--no-ai`, `--provider=`,
`--model=`, `--ai-task=`, `-v`. All options: [docs/commands.md](docs/commands.md).

## Analyzers

| Analyzer | What it reports |
|---|---|
| `environment` | Laravel version and support status, PHP requirement, Composer/Node/npm versions, frontend stack, infrastructure drivers. |
| `dependencies` | Direct and locked packages, lock drift, installed drift, PHP compatibility, abandoned/outdated packages, Packagist advisories, insecure Composer settings. |
| `routes` | Routes reconstructed from `bootstrap/app.php` / `RouteServiceProvider` and route files (groups, prefixes, resources, controller groups, controller middleware), access classification, duplicates, missing controllers. |
| `configuration` | App, session, cache, queue, logging, CORS, filesystem, auth and hashing settings with their source; production misconfiguration; `.env` keys missing from `.env.example` (names only). |
| `database` | Default connection and technology, connections, tables, foreign keys and indexes from migrations, possibly missing indexes, unconstrained `foreignId()`, database packages. |
| `security` | Debug mode in production, `APP_KEY` problems, session cookie flags, CORS, bcrypt cost, debug tooling, sensitive files in `public/` or git, file permissions, risky routes, CSRF exclusions, trusted proxies, advisories, hardcoded secrets, command/SQL injection patterns, unsafe `unserialize`, path/URL from input, open redirects, mass assignment, weak password hashing, unescaped Blade output. |
| `architecture` | Controllers, models, services, repositories, jobs, events, Livewire/Filament/Inertia, integrations, auth packages, large classes. |
| `quality` | PHPStan/Larastan/Psalm, Pint/PHP CS Fixer/PHP_CodeSniffer, Rector, test framework and test files, CI configuration; optional report-only tool runs. |

## Reading a report

Every finding has a **severity** (`CRITICAL`, `HIGH`, `MEDIUM`, `LOW`, `INFO`), a **confidence**
(*confirmed* from evidence, or *potential* - a risk that needs review), the affected component, an
explanation, evidence and a remediation. Facts are labelled *detected* (read directly), *inferred*
(from constraints, defaults or `.env.example`) or *unknown* (would need the application to run).
Code-pattern findings are always *potential*: the analysis is local to single expressions, so they
need review, and the absence of a finding is not proof of safety.

## AI-assisted analysis

```bash
larainspect config:init            # creates the settings file (mode 600)
# set AI_PROVIDER and the provider's API key (and model, except for Anthropic)
larainspect config:test            # one tiny request, no project data
larainspect ai ~/projects/shop --dry-run   # review the prompts; nothing is sent
larainspect ai ~/projects/shop
```

| Provider | `AI_PROVIDER` | Key variable | Default model |
|---|---|---|---|
| Anthropic | `anthropic` | `ANTHROPIC_API_KEY` | `claude-opus-5-5` |
| OpenAI | `openai` | `OPENAI_API_KEY` | none - set `OPENAI_MODEL` |
| OpenRouter | `openrouter` | `OPENROUTER_API_KEY` | none - set `OPENROUTER_MODEL` |
| NoTokenLimit | `notokenlimit` | `NOTOKENLIMIT_API_KEY` | none - set `NOTOKENLIMIT_MODEL` |

Tasks: `explain-findings`, `architecture`, `dependencies`, `security-fixes`, `documentation`,
`technical-debt`, `best-practices` (default: the first, fourth and sixth; one request each).
Details: [docs/ai-providers.md](docs/ai-providers.md).

## CI example

```yaml
# .github/workflows/inspect.yml in the repository you want to check
jobs:
  inspect:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { path: app }
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - run: |
          git clone --depth 1 <repository-url> inspector
          composer install --no-dev --working-dir=inspector
      - run: php inspector/bin/larainspect inspect app --format=markdown --output=inspection.md --fail-on=high
      - if: always()
        uses: actions/upload-artifact@v4
        with: { name: inspection, path: inspection.md }
```

## Development

```bash
composer install
composer check       # PHPStan level 8 + PHPUnit
composer docs        # regenerate docs/commands.md
LARAINSPECT_NETWORK_TESTS=1 vendor/bin/phpunit --filter Packagist   # opt-in test against real Packagist
```

The test suite needs no network access, API keys or Laravel installation: it uses fake projects in
`tests/Fixtures/projects`, recorded Composer output and mocked HTTP responses for AI providers.

## License

MIT - see [LICENSE](LICENSE).
