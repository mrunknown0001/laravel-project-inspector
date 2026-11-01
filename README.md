# Laravel Project Inspector

A standalone, read-only CLI that inspects existing Laravel projects and generates technical reports.
It does not need to be installed inside the project it inspects.

> **Status: Phase 2 (core MVP).** Project detection, environment and dependency analysis, and
> terminal/Markdown/HTML/JSON reports work today. Route, configuration, database, security,
> architecture and code-quality analyzers (Phase 3) and optional AI providers (Phase 4) are planned.

## Safety guarantees

- **The inspected project is never modified.** File access goes through a read-only reader confined to
  the project root (path traversal and escaping symlinks are rejected). Reports and logs are refused if
  they would land inside the project.
- **Project code is never executed.** `config/*.php` and other PHP files are parsed as syntax trees,
  never included. `artisan` is not run.
- **Secrets stay local.** Only an allowlist of non-sensitive `.env` keys is read (e.g. `APP_ENV`,
  `DB_CONNECTION`). Every report passes through a redactor that removes `.env` secret values, API
  tokens, private keys and URL credentials.
- **External commands are restricted.** Only allowlisted executables (`composer`, `node`, `npm`, ...)
  run, without a shell, with timeouts. Composer always runs with `--locked --no-plugins --no-scripts`
  and is never asked to install or update. Every command is recorded in `reports/audit.log` and in the
  report's *Operations* section.
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
| `report [path]` | Render saved results (latest, or `--from=file.json`), or inspect `path` first. Defaults to Markdown. |

### Options

| Option | Description |
|---|---|
| `--only=a,b` / `--exclude=a,b` | Select analyzers (`inspect`, `report`). Available: `environment`, `dependencies`. |
| `--format=` | `console` (default for `inspect`), `markdown`, `html`, `json`. |
| `--output=` | Report file path, or `-` for standard output. Must be outside the inspected project. |
| `--offline` | Skip Packagist checks. |
| `--no-save` | Write no files at all (no saved results, no audit log). |
| `--fail-on=` | Exit with code 2 when a finding is at or above this severity. |
| `-v` | Verbose output. |

Results are saved as JSON in `./reports` (relative to where you run the command), next to the
audit log. Run the inspector from outside the project directory, or use `--no-save --output=...`.

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
