# Installation

The inspector is a standalone PHP application. It is installed once, outside the projects it
inspects, and never needs to be added to a Laravel project.

## Requirements

| | Required | Notes |
|---|---|---|
| PHP | 8.3 or later | Extensions: `json`, `mbstring`, `tokenizer` (all default builds include them). `curl` or `openssl` for HTTPS. |
| Composer | 2.x | To install the inspector. At runtime it is used only for Packagist advisory/outdated checks (skip with `--offline`). |
| git | optional | Lets the security analyzer tell committed files from local ones. |
| Node.js / npm | optional | Only reported as versions in the environment section. |

Check with:

```bash
php -v
composer --version
```

## Linux and macOS

```bash
git clone <repository-url> ~/tools/laravel-project-inspector
cd ~/tools/laravel-project-inspector
composer install --no-dev
php bin/larainspect --version
```

Put it on your `PATH` (pick a directory that is already on it, such as `~/.local/bin` or
`/usr/local/bin`):

```bash
ln -s ~/tools/laravel-project-inspector/bin/larainspect ~/.local/bin/larainspect
larainspect --version
```

macOS with Homebrew PHP works the same way (`brew install php composer`).

## Windows

1. Install PHP 8.3+ (for example from [windows.php.net](https://windows.php.net/download/), or with
   `winget install PHP.PHP.8.3`) and make sure `php` works in a new terminal.
2. Install Composer with the [Composer-Setup.exe](https://getcomposer.org/download/) installer.
3. In PowerShell or Command Prompt:

```powershell
git clone <repository-url> C:\Tools\laravel-project-inspector
cd C:\Tools\laravel-project-inspector
composer install --no-dev
bin\larainspect.bat --version
```

To run `larainspect` from anywhere, add `C:\Tools\laravel-project-inspector\bin` to your user
`PATH` (Settings, System, About, Advanced system settings, Environment Variables), then open a new
terminal:

```powershell
larainspect inspect C:\Projects\my-laravel-app
```

Windows notes:

- File permission checks are skipped on Windows (NTFS permissions are not POSIX modes); the report
  says so.
- Paths are case-insensitive for the "never write inside the inspected project" check.
- The per-user settings file is `%APPDATA%\larainspect\.env`.
- The test suite skips symlink tests on Windows, where creating symlinks needs extra privileges.
- The inspector has been developed and tested on Linux and WSL. Windows support is implemented
  (launcher, path handling, executable lookup through `PATHEXT`) but has not been run on a Windows
  machine yet; please report problems.

## WSL (Windows Subsystem for Linux)

Install as on Linux inside the WSL distribution. Projects on the Windows drive (`/mnt/c/...`) work,
with two differences the report points out:

- permission checks are skipped, because drvfs/9p mounts report every file as mode 777;
- git may refuse to read a repository owned by your Windows user ("dubious ownership"). The
  inspector respects that protection, so "committed file" checks are skipped. To enable them, mark
  the repository as safe yourself: `git config --global --add safe.directory /mnt/c/path/to/project`.

For speed, keep projects you inspect often inside the WSL filesystem (`~/projects`).

## As a global Composer package

```bash
composer global config repositories.larainspect vcs <repository-url>
composer global require larainspect/laravel-project-inspector:dev-main
larainspect --version
```

Composer's global `vendor/bin` directory must be on your `PATH`
(`composer global config bin-dir --absolute` prints it).

When installed this way the inspector lives inside Composer's `vendor/` directory, so
`larainspect config:init` creates the settings file in your user configuration directory
(`~/.config/larainspect/.env`, or `%APPDATA%\larainspect\.env`) where updates cannot overwrite it.

## Settings file

AI settings (and only AI settings) are read from, in order:

1. environment variables,
2. the file named by `LARAINSPECT_ENV_FILE`, if set,
3. `.env` in the inspector directory (git-checkout installs),
4. the per-user file described above.

`larainspect config:init` creates the file from `.env.example` with mode 600; `larainspect
config:show` shows which file is in use and where each value came from. See
[ai-providers.md](ai-providers.md).

## Upgrading

```bash
cd ~/tools/laravel-project-inspector
git pull
composer install --no-dev
```

Saved results in `reports/` carry a schema version; older files that the new version cannot read
are rejected with a clear message rather than misread.

## Uninstalling

Delete the inspector directory and the `PATH` entry or symlink. Optionally delete the per-user
settings file and any `reports/` directories in the folders you ran it from. Nothing was installed
into inspected projects.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `requires PHP 8.3 or later` | `php` on your `PATH` is older. Check `php -v`; on Windows, check which `php.exe` comes first in `PATH`. |
| `Dependencies are not installed` | Run `composer install --no-dev` in the inspector directory. |
| `Refusing to write to ... inside the inspected project` | You ran the command from inside the project, so `./reports` would be inside it. Run it from another directory, or use `--no-save --output=<path outside the project>`. |
| `is not a Laravel application` | The directory has no `laravel/framework` requirement or no `artisan` / `bootstrap/app.php`. Point it at the application root. |
| Advisory checks "failed" | No network access, or Packagist is unreachable. Use `--offline`, or retry. |
| "Committed file" checks skipped | Not a git checkout, git not installed, or git refuses the repository (see WSL above). |
| Composer warnings in the audit log | Normal for projects whose lock file is out of date; the dependency section explains the drift. |
