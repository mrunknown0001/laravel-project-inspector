# Laravel Project Inspector

A standalone, read-only CLI that inspects existing Laravel projects and generates technical reports.
It does not need to be installed inside the project it inspects.

> **Status: Phase 3 complete.** All eight analyzers (environment, dependencies, routes, configuration,
> database, security, architecture, code quality) and terminal/Markdown/HTML/JSON reports work.
> Optional AI-assisted analysis (Phase 4) is planned.

## Safety guarantees

- **The inspected project is never modified.** File access goes through a read-only reader confined to
  the project root (path traversal and escaping symlinks are rejected). Reports and logs are refused if
  they would land inside the project.
- **Project code is never executed by default.** Route files, config files, migrations and classes are
  parsed as syntax trees, never included. `artisan` and the project's own tools only run when you opt in
  (`--use-artisan`, `--run-quality-tools`) **and** confirm an interactive prompt that shows the exact
  command and what it may change. Non-interactive sessions (CI, `-n`) always refuse.
- **The database is never connected to.** Schema information comes from migration files.
- **Secrets stay local.** Only an allowlist of non-sensitive `.env` keys is read (e.g. `APP_ENV`,
  `DB_CONNECTION`). Every report passes through a redactor that removes `.env` secret values, API
  tokens, private keys and URL credentials.
- **External commands are restricted.** Only allowlisted executables (`composer`, `git`, `node`, `npm`,
  `php`, ...) run, without a shell, with timeouts. Composer always runs with `--locked --no-plugins
  --no-scripts` and is never asked to install or update; `git` only runs `ls-files` with fsmonitor
  disabled. Every command is recorded in `reports/audit.log` and in the report's *Operations* section.
- **Network use is limited and optional.** Only `composer audit` and `composer outdated` contact
  Packagist (package names and versions only). `--offline` disables both.

## Requirements

- PHP 8.3+ with `json`, `mbstring` and `tokenizer`
- Composer 2.x (also used at runtime for advisory/outdated checks; optional with `--offline`)

## Installation

### Linux / macOS / WSL

```bash
git clone <repository-url> laravel-project-inspector
cd laravel-project-inspector
composer install --no-dev
php bin/larainspect --version
```

Optionally put it on your `PATH`:

```bash
ln -s "$PWD/bin/larainspect" ~/.local/bin/larainspect
```

### Windows

```powershell
git clone <repository-url> laravel-project-inspector
cd laravel-project-inspector
composer install --no-dev
bin\larainspect.bat --version
```

Add the `bin` directory to your `PATH` to run `larainspect` from anywhere.

## Usage

```bash
larainspect inspect /path/to/project               # all analyzers, terminal output
larainspect inspect /path/to/project -v            # include evidence, remediation, operations
larainspect inspect /path/to/project --offline     # no network access
larainspect environment /path/to/project           # one analyzer
larainspect dependencies /path/to/project
larainspect routes /path/to/project -v
larainspect security /path/to/project --fail-on=high
larainspect architecture /path/to/project
larainspect inspect ./app --only=security,routes   # a selection of analyzers
larainspect inspect ./app --format=html --output=inspection.html
larainspect report --format=markdown               # re-render the most recent saved results
larainspect inspect ./app --fail-on=high           # exit code 2 if HIGH or CRITICAL findings exist (CI)
```

`analyze` is an alias of `inspect`.

### Commands

| Command | Description |
|---|---|
| `inspect <path>` | Run every analyzer (or a selection) and print the report. |
| `environment <path>` | PHP/Laravel/tooling versions, frontend stack, infrastructure drivers. |
| `dependencies <path>` | Lock drift, PHP compatibility, advisories, abandoned and outdated packages, insecure Composer settings. |
| `routes <path>` | Route list with middleware, controllers and access level; routes that need review. |
| `security <path>` | Rule-based security checks (see below). |
| `architecture <path>` | Component inventory, patterns, frontend stack, integrations and an overview. |
| `report [path]` | Render saved results (latest, or `--from=file.json`), or inspect `path` first. Defaults to Markdown. |

### Options

| Option | Description |
|---|---|
| `--only=a,b` / `--exclude=a,b` | Select analyzers (`inspect`, `report`): `environment`, `dependencies`, `routes`, `configuration`, `database`, `security`, `architecture`, `quality`. |
| `--use-artisan` | List routes with `php artisan route:list` (includes package routes). Boots the project; asks first. |
| `--run-quality-tools[=list]` | Run PHPStan, Pint (`--test`), PHP CS Fixer (`--dry-run`) and PHP_CodeSniffer in report-only mode; asks first. |
| `--format=` | `console` (default for `inspect`), `markdown`, `html`, `json`. |
| `--output=` | Report file path, or `-` for standard output. Must be outside the inspected project. |
| `--offline` | Skip Packagist checks. |
| `--no-save` | Write no files at all (no saved results, no audit log). |
| `--fail-on=` | Exit with code 2 when a finding is at or above this severity. |
| `-v` | Verbose output. |

Results are saved as JSON in `./reports` (relative to where you run the command), next to the
audit log. Run the inspector from outside the project directory, or use `--no-save --output=...`.

### Analyzers

| Analyzer | What it reports |
|---|---|
| `environment` | Laravel version and support status, PHP requirement, Composer/Node/npm versions, frontend stack, infrastructure drivers. |
| `dependencies` | Direct and locked packages, lock drift, installed drift, PHP compatibility, abandoned/outdated packages, Packagist advisories, insecure Composer settings. |
| `routes` | Routes read from `bootstrap/app.php` / `RouteServiceProvider` and route files (groups, prefixes, resources, controller groups, controller middleware), access classification, duplicates, missing controllers. |
| `configuration` | App, session, cache, queue, logging, CORS, filesystem, auth and hashing settings with their source; production misconfiguration; `.env` keys missing from `.env.example` (names only). |
| `database` | Default connection and technology, connections, tables/foreign keys/indexes from migrations, possibly missing indexes, unconstrained `foreignId()`, database packages. |
| `security` | Debug mode in production, `APP_KEY` problems, session cookie flags, CORS, bcrypt cost, debug tooling, sensitive files in `public/` or git, file permissions, risky routes, CSRF exclusions, trusted proxies, advisories, hardcoded secrets, command/SQL injection patterns, unsafe `unserialize`, path/URL from input, open redirects, mass assignment, weak password hashing, unescaped Blade output. |
| `architecture` | Controllers, models, services, repositories, jobs, events, Livewire/Filament/Inertia, integrations, auth packages, large classes. |
| `quality` | PHPStan/Larastan/Psalm, Pint/PHP CS Fixer/PHP_CodeSniffer, Rector, test framework and test files, CI configuration; optional tool runs. |

Security findings are **confirmed** only when the evidence proves the condition (for example a value read
from `.env`); code-pattern findings are always **potential** because the analysis is local to a single
expression. Secret values never appear in reports: evidence shows a location and a masked prefix.

### Reading a report

Every finding has a severity (`CRITICAL`, `HIGH`, `MEDIUM`, `LOW`, `INFO`), a confidence level
(**confirmed** from evidence, or **potential** risk that needs review), the affected component,
an explanation, evidence and a remediation. Facts are labelled **detected** (read directly),
**inferred** (from constraints, defaults or `.env.example`) or **unknown** (needs runtime evaluation).

See [docs/examples](docs/examples) for sample reports generated from the test fixtures.

## Development

```bash
composer install
composer test        # PHPUnit
composer analyse     # PHPStan (level 8)
LARAINSPECT_NETWORK_TESTS=1 vendor/bin/phpunit --filter Packagist   # opt-in test against real Packagist
```

The test suite needs no network access, API keys or Laravel installation; it uses fake projects in
`tests/Fixtures/projects` and recorded Composer output.

## License

MIT
