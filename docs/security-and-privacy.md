# Security and privacy

The inspector is meant to be pointed at projects you do not fully trust yet (an inherited codebase,
a client project, a pull request). This page describes what it reads, what it runs, what leaves
your machine, and the limits of its guarantees.

## Guarantees

| Guarantee | How it is enforced |
|---|---|
| The inspected project is not modified. | Project files are read through `SafeFileReader`, which has no write methods. Reports, saved results and the audit log are written only outside the project; a target inside it (directly, via `..`, or via a symlink) is refused. Tests fingerprint every fixture project before and after each CLI test. |
| Project code is not executed by default. | Route files, config files, migrations and classes are parsed into syntax trees with nikic/php-parser and never included. `.env` files are parsed by an in-house parser without variable interpolation or command substitution. |
| File access stays inside the project. | `PathGuard` resolves `..` lexically (it can never climb above the root), rejects absolute paths, stream wrappers and null bytes, and checks the real path of existing files so symlinks cannot point outside. Directory scans do not follow symlinks. |
| Secrets are not shown or stored. | Only an allowlist of configuration keys is read from `.env` (`APP_ENV`, `APP_DEBUG`, `DB_CONNECTION`, driver names...). Other values are kept in memory only, to scrub them from output. Every report passes through a redactor that removes those exact values, known token formats (API keys, private keys, JWTs, `APP_KEY` values), URL passwords and sensitive assignments. Secret findings show a location and a masked prefix, never the value. |
| External commands are constrained. | Only allowlisted executables run (`composer`, `git`, `php`, `node`, `npm`, `pnpm`, `yarn`, `bun`), as argument arrays without a shell, with timeouts. They are looked up only in absolute `PATH` entries: an empty or relative entry (which means "the current directory") is ignored, so a project's own `./git` or `./composer` can never be picked up. Version checks run from the system temp directory, not the project. |

## What runs, and when

| Operation | When | Notes |
|---|---|---|
| `composer --version`, `node --version`, `npm --version`, ... | Every run of the environment analyzer | Run outside the project. |
| `git -c core.fsmonitor=false -C <project> ls-files -z` | Security analyzer | Reads git's index. fsmonitor is disabled so a hostile repository config cannot make git run a command, and git's `safe.directory` protection is respected (not overridden). |
| `composer audit --locked --no-plugins --no-scripts` and `composer outdated --direct --locked --no-plugins --no-scripts` | Dependency/security analyzers, unless `--offline` | Read `composer.lock`; plugins and scripts from the project never load. Contacts Packagist. |
| `php artisan route:list --json` | Only with `--use-artisan` **and** interactive confirmation | Boots the application: its service providers run, it may connect to configured services, and it may write to `storage/` and `bootstrap/cache/`. Refused in non-interactive sessions. |
| `php vendor/bin/phpstan`, `pint --test`, `php-cs-fixer fix --dry-run`, `phpcs` | Only with `--run-quality-tools` **and** interactive confirmation | Runs the project's own tooling (and Larastan boots the app). Report-only modes; caches may be written. |

Every external command and every AI request is recorded in `reports/audit.log` (JSON lines:
time, purpose, command or URL, outcome, duration, whether it used the network) and in the
report's *Operations* section. Commands that were refused are recorded too.

## What leaves your machine

| Destination | When | What is sent |
|---|---|---|
| Packagist (`repo.packagist.org`, via Composer) | Unless `--offline` | Names and versions of locked packages (the same information `composer audit` always sends). |
| Your AI provider | Only with `larainspect ai`, `--ai`, or `AI_ENABLED=true` | For each task: a fixed system prompt and a JSON summary of the redacted inspection results (findings, facts, notes, selected summary data). Never source code, `.env` files, the absolute project path (replaced by `<project>`), the audit log or full route lists. `larainspect ai --dry-run` prints exactly what would be sent. |
| Nowhere else | | The inspector has no telemetry. |

The AI provider receives your project's *findings* - for example that debug mode is on in
production, or which packages have advisories. Treat that as sensitive security information and
choose a provider whose data handling you accept. NoTokenLimit in particular is a third-party
reseller that is not affiliated with the model vendors; see [ai-providers.md](ai-providers.md).

AI output is rendered in its own section, labelled as AI-generated in every format, and is passed
through the redactor as well. It never creates findings, changes severities or affects
`--fail-on`.

## API keys

- Keys are read from environment variables or the inspector's settings file, never from the
  inspected project.
- They are sent only in request headers to the configured base URL, never in URLs. Redirects are
  never followed, so a key header cannot be forwarded to another host.
- The "never write inside the project" check compares real paths, ignores case on Windows and
  macOS, and uses the project root from the path guard, never a value taken from report data.
- They are masked in `config:show`, `ai:providers`, debug dumps of the configuration objects, error
  messages (providers sometimes echo keys back) and the audit log.
- `config:init` creates the settings file with mode 600; `.env` is git-ignored in this repository.
- A base URL must use `https://` (plain `http://` is accepted only for `localhost`).

## Limits

- **Static analysis cannot see everything.** Routes and middleware registered at runtime by
  packages, configuration computed in code, and indexes created outside migrations are not
  visible. The report says when this applies.
- **Code-pattern findings are local.** Checks for command/SQL injection, unsafe `unserialize()`,
  path or URL handling and mass assignment look at single expressions; input passed through
  variables is not traced. Such findings are always marked *potential*, and the absence of a
  finding is not proof of safety.
- **Redaction is pattern-based.** It removes known formats and every value of a sensitive key in
  the project's `.env`, but a secret in an unusual format that appears somewhere else could in
  principle survive. Review reports before sharing them, and use `--dry-run` before sending results
  to an AI provider.
- **Opt-in operations really execute project code.** `--use-artisan` and `--run-quality-tools` are
  equivalent to running those commands yourself in the project. Only confirm them for projects you
  trust, ideally in a disposable copy or container.
- **The inspector does not replace a security audit or penetration test.**

## Reporting a vulnerability

If you find a way to make the inspector modify an inspected project, execute its code without
confirmation, read files outside it, or leak a secret into a report, a log or an AI request, please
report it privately to the maintainers rather than opening a public issue.
