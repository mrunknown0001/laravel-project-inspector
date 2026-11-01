# Laravel Project Inspector

**Understand, audit and document any Laravel project in minutes, without touching it.**

`larainspect` is a standalone command-line tool. Point it at a Laravel application and it reports
the stack and versions, dependency problems, routes and their access control, configuration,
database schema, security risks, architecture and code quality. It can also ask an AI model to
explain the results.

![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)
![Laravel 8 to 13](https://img.shields.io/badge/Laravel-8%E2%80%9313-FF2D20)
![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)
![License MIT](https://img.shields.io/badge/license-MIT-blue)

```bash
larainspect inspect ~/projects/shop                    # full report in the terminal
larainspect security ~/projects/shop --fail-on=high    # CI gate
larainspect inspect ~/projects/shop --format=html      # one self-contained HTML file
larainspect ai ~/projects/shop                         # AI remediation plan (optional)
```

---

## Why

Inheriting a Laravel codebase, reviewing a client project or picking up an old side project usually
starts with the same hours of digging. Which Laravel version is it, and is it still supported? Is
the lock file in sync? Which routes are public? Is debug mode on in production? Are secrets
committed? Where does the business logic live?

The inspector answers those questions in one run. It treats the project as untrusted:

- **It is read-only.** It never modifies the project, and never writes reports or logs inside it.
- **It runs no project code by default.** Routes, config files, migrations and classes are parsed,
  not executed. It never connects to the database.
- **Secrets stay put.** `.env` values never appear in reports, logs or AI prompts.

## What you get

```text
$ larainspect security ./insecure-app --offline

Findings
--------
  CRITICAL  1. .env is committed to version control
  HIGH      2. Debug mode is enabled in a production environment
  HIGH      3. APP_KEY is not set
  HIGH      5. CORS allows any origin together with credentials
  HIGH     11. A public route calls phpinfo()
  HIGH     13. CSRF protection is disabled for all routes
  HIGH     16. Raw SQL built with request input (1 occurrence) (potential)
  ...

Summary
-------
  CRITICAL 1    HIGH 16    MEDIUM 16    LOW 7    INFO 1
```

With `-v`, every finding shows its explanation, evidence and fix:

```text
  HIGH  16. Raw SQL built with request input (1 occurrence) (potential)
    Request input is concatenated or passed directly into a raw SQL expression, which allows SQL injection.
    │ - app/Http/Controllers/ReportController.php:14: $rows = DB::select("SELECT * FROM orders WHERE status = '" . $request->input('status') . "'");
    Fix: Use query builder methods or bindings: whereRaw("email = ?", [$email]); never concatenate input into SQL.
```

Every finding has a severity (`CRITICAL` to `INFO`) and a confidence:

- *confirmed*: proven by the evidence, for example a value read from `.env`;
- *potential*: a pattern worth reviewing.

Facts are labelled *detected* (read directly), *inferred* (from defaults, constraints or
`.env.example`) or *unknown* (would need the application to run). The inspector says which.

Full sample reports: [Markdown](docs/examples/insecure-app-report.md),
[HTML](docs/examples/insecure-app-report.html), [JSON](docs/examples/laravel-8-report.json).

## Quick start

You need PHP 8.3+ and Composer 2.

```bash
git clone <repository-url> ~/tools/laravel-project-inspector
cd ~/tools/laravel-project-inspector
composer install --no-dev
ln -s "$PWD/bin/larainspect" ~/.local/bin/larainspect

cd ~                                   # run from outside the project you inspect
larainspect inspect ~/projects/shop
```

On Windows, run `composer install --no-dev`, then add the `bin\` folder to your `PATH` and use
`larainspect` (it runs `larainspect.bat`). The [installation guide](docs/installation.md) covers
Linux, macOS, Windows, WSL and installing with Composer.

## Common workflows

**Get to know an unfamiliar project**

```bash
larainspect environment ./app          # versions, support status, frontend stack, drivers
larainspect architecture ./app         # components, patterns, integrations and a plain-English overview
larainspect routes ./app -v            # every route with middleware, controller and access level
```

**Security review**

```bash
larainspect security ./app -v
larainspect inspect ./app --format=html --output=review.html
```

**Gate a CI pipeline**

```bash
larainspect inspect ./app --format=markdown --output=inspection.md --fail-on=high   # exit code 2 on HIGH/CRITICAL
```

**Report from earlier results without inspecting again**

```bash
larainspect report --format=markdown                          # latest saved results
larainspect report --from=reports/shop-20261101-120000.json --format=html
```

**Explain and plan with AI (optional)**

```bash
larainspect ai ./app --dry-run                                # see exactly what would be sent
larainspect ai ./app --provider=anthropic
larainspect ai --ai-task=documentation,dependencies           # over the latest saved results
```

Each run saves its results as JSON in `./reports`, in the directory you run the command from,
together with an audit log of every external command.

## Commands

| Command | Purpose |
|---|---|
| `inspect <path>` | Run every analyzer, or a selection with `--only` / `--exclude`. Alias: `analyze`. |
| `environment <path>` | Laravel and PHP versions and support status, tooling, frontend stack, infrastructure drivers. |
| `dependencies <path>` | Lock drift, PHP compatibility, security advisories, abandoned and outdated packages. |
| `routes <path>` | Route list with middleware, controllers and access control; routes that need review. |
| `security <path>` | Rule-based security checks. |
| `architecture <path>` | Component inventory, patterns, frontend stack and integrations. |
| `report [path]` | Render saved results, or inspect first. Defaults to Markdown. |
| `ai [path]` | AI-assisted analysis of a new inspection or of saved results. |
| `ai:providers`, `ai:models` | Provider status (keys masked) and the models a provider offers. |
| `config:show`, `config:init`, `config:test` | Show settings and their source, create the settings file, test a provider. |

Frequently used options:

| Option | Effect |
|---|---|
| `--format=console\|markdown\|html\|json` | Output format. |
| `--output=<file>` or `--output=-` | Write the report to a file, or to standard output. |
| `--offline` | Skip the checks that contact Packagist. |
| `--fail-on=<severity>` | Exit with code 2 if any finding is at or above this severity. |
| `--only` / `--exclude` | Choose which analyzers run. |
| `--no-save` | Write no files at all. |
| `-v` | Show evidence, fixes and the operations log. |
| `--ai`, `--provider`, `--model`, `--ai-task` | Add AI analysis, and choose its provider, model and tasks. |

Every option is listed in the [command reference](docs/commands.md).

## What it checks

| Analyzer | Highlights |
|---|---|
| **environment** | Laravel version and support window, PHP requirement, Composer/Node/npm versions, Vite or Mix, Livewire/Inertia/Vue/React, database, cache, queue and session drivers. |
| **dependencies** | Lock file out of sync, locked versions that no longer match `composer.json`, PHP version conflicts, vendor drift, abandoned packages, Packagist security advisories, outdated packages, insecure Composer settings. |
| **routes** | Routes rebuilt from `bootstrap/app.php` or `RouteServiceProvider`, including groups, prefixes, resources, controller groups and middleware declared in controllers. Each route is classified by access control; duplicates and missing controllers are flagged. |
| **configuration** | App, session, cache, queue, logging, CORS, filesystem, auth and hashing settings with their source; production misconfiguration; `.env` keys missing from `.env.example` (names only). |
| **database** | Connections and technology, and tables, foreign keys and indexes read from migrations; likely missing indexes and unconstrained `foreignId()` columns. |
| **security** | Debug mode in production, `APP_KEY` problems, cookie flags, CORS, bcrypt cost, debug tools, sensitive files in `public/` or git, permissions, risky public routes, CSRF exclusions, trusted proxies, hardcoded secrets (masked), command and SQL injection patterns, unsafe `unserialize`, open redirects, mass assignment, weak password hashing, unescaped Blade output. |
| **architecture** | Controllers, models, services, repositories, actions, jobs, events, Livewire/Filament/Inertia, third-party integrations, authentication packages, oversized classes, and a plain-English overview. |
| **quality** | PHPStan/Larastan/Psalm, Pint/PHP CS Fixer/PHP_CodeSniffer, Rector, tests and CI configuration. Tools can optionally be run in report-only mode. |

## AI-assisted analysis

AI is optional and off by default. When you ask for it, the model receives only the redacted
inspection results: findings, facts and summaries. It never receives source code, `.env` files,
absolute paths or the audit log. Its output appears in a separate section labelled
*AI-generated*. It never adds findings or changes severities.

```bash
larainspect config:init      # creates the settings file (mode 600)
# set AI_PROVIDER and the provider's API key
larainspect config:test      # one tiny request containing no project data
```

| Provider | `AI_PROVIDER` | Key | Default model |
|---|---|---|---|
| Anthropic | `anthropic` | `ANTHROPIC_API_KEY` | `claude-opus-5-5` |
| OpenAI | `openai` | `OPENAI_API_KEY` | none; set `OPENAI_MODEL` |
| OpenRouter | `openrouter` | `OPENROUTER_API_KEY` | none; set `OPENROUTER_MODEL` |
| NoTokenLimit | `notokenlimit` | `NOTOKENLIMIT_API_KEY` | none; set `NOTOKENLIMIT_MODEL` |

| Task | What you get |
|---|---|
| `explain-findings` | The findings in plain language, most severe first. |
| `security-fixes` | A prioritised remediation plan with Laravel-specific fixes. |
| `technical-debt` | Debt themes with effort and risk, and an ordered plan. |
| `architecture` | How the app is built, with its strengths, risks and a few improvements. |
| `dependencies` | The conflicts explained, with a safe upgrade order. |
| `documentation` | A technical overview draft for a README or onboarding wiki. |
| `best-practices` | Recommendations specific to this project's version and stack. |

By default the first three tasks run, one API request each. Switch provider per run with
`--provider=...`, and use any model ID the provider accepts with `--model=...`. See the
[AI providers guide](docs/ai-providers.md) for every setting.

## Safety model

| Guarantee | How |
|---|---|
| Never modifies the project | Files are read through a reader with no write methods, confined to the project root. Output inside the project is refused, including through `..` or symlinks. |
| Runs no project code by default | PHP is parsed with nikic/php-parser, never included. `artisan route:list` and the project's own tools run only with `--use-artisan` / `--run-quality-tools` **and** an interactive confirmation that shows what they may change. |
| Keeps secrets out of output | Only harmless `.env` keys are read. Every report and AI prompt passes through a redactor. Secret findings show a masked prefix only. |
| Constrains external commands | Allowlisted executables, no shell, timeouts. Composer runs with `--locked --no-plugins --no-scripts`. git runs with fsmonitor disabled. |
| Records what it did | Every command and AI request is logged to `reports/audit.log` and to the report's Operations section. |
| Sends nothing silently | No telemetry. Packagist sees package names only, and `--offline` stops even that. AI providers receive data only when asked. |

The guarantees, what runs when, and the limits are described in
[security and privacy](docs/security-and-privacy.md).

## Use it in CI

```yaml
# .github/workflows/inspect.yml
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

Exit codes:

| Code | Meaning |
|---|---|
| 0 | Success. |
| 1 | Error, such as an invalid path, a folder that isn't a Laravel project, or an unconfigured AI provider. |
| 2 | A finding met the `--fail-on` threshold. |

## Platform support

| Platform | Status |
|---|---|
| Linux, WSL | Developed and tested. |
| macOS | Expected to work; uses the same code paths as Linux. |
| Windows | Implemented (`.bat` launcher, path handling, `PATHEXT` lookup) but not yet tested on a Windows machine. |

Inspected projects can use Laravel 8 or later. Older versions are inspected on a best-effort basis
and the report says so. Lumen is recognised but not supported.

## Documentation

| Guide | |
|---|---|
| [Installation](docs/installation.md) | All platforms, Composer global install, upgrading, troubleshooting. |
| [Command reference](docs/commands.md) | Every command and option, generated from the code. |
| [AI providers](docs/ai-providers.md) | Setup per provider, settings, and exactly what is sent. |
| [Security and privacy](docs/security-and-privacy.md) | Guarantees, what runs, what leaves your machine, limits. |
| [Extending](docs/extending.md) | Add analyzers, security rules, AI providers, AI tasks or report formats. |
| [Changelog](CHANGELOG.md) | Release notes. |

## Limitations

- **Static analysis has blind spots.** Routes registered at runtime by packages are not visible
  unless you pass `--use-artisan`. The same goes for configuration computed in code and indexes
  created outside migrations. The report notes when this applies.
- **Code-pattern checks are local.** They look at single expressions and do not trace data
  through variables. A *potential* finding needs review, and no finding is not proof of safety.
- **It does not replace a security audit or a penetration test.**

## Development

```bash
composer install
composer check      # PHPStan level 8 + PHPUnit (300 tests, no network or API keys needed)
composer docs       # regenerate docs/commands.md from the command definitions
LARAINSPECT_NETWORK_TESTS=1 vendor/bin/phpunit --filter Packagist   # optional live Packagist test
```

```text
src/
├── Analyzers/        one class per report section
├── Security/Rules/   pluggable security checks
├── AI/               provider adapters, tasks, prompt builder
├── Routing/          static route reconstruction
├── Parsing/          .env, config and PHP readers (no execution)
├── Reports/          console, Markdown, HTML, JSON
└── Core/, Process/, Security/, Support/
```

Tests run against fake Laravel projects in `tests/Fixtures/projects`, including a deliberately
insecure one, using recorded Composer output and mocked AI responses. To add a check or a
provider, see [docs/extending.md](docs/extending.md).

## License

MIT. See [LICENSE](LICENSE).
