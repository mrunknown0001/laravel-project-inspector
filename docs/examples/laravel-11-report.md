# Laravel Project Inspection: fixture/laravel-11-app

> The inspected project was not modified. Files were read without executing project code. External commands are listed under Operations.

| | |
|---|---|
| **Project** | fixture/laravel-11-app |
| **Path** | /path/to/laravel-11 |
| **Framework** | Laravel 11.34.2 |
| **Generated** | 2026-11-01 13:29:17 +00:00 |
| **Inspector version** | 0.1.0 |
| **Duration** | 0.4 s |
| **Network checks** | Disabled (offline) |

## Summary

7 findings, including 1 high severity: review soon.

| Severity | Count |
|---|---:|
| CRITICAL | 0 |
| HIGH | 1 |
| MEDIUM | 1 |
| LOW | 2 |
| INFO | 3 |

## Findings

| # | Severity | Confidence | Finding | Component |
|---:|---|---|---|---|
| 1 | **HIGH** | confirmed | [1 locked package cannot run on the pinned PHP version](#finding-1) | `composer.lock` |
| 2 | **MEDIUM** | confirmed | [Laravel 11 no longer receives security fixes](#finding-2) | `composer.lock` |
| 3 | **LOW** | confirmed | [.env is world-readable](#finding-3) | `.env` |
| 4 | **LOW** | confirmed | [No automated tests found](#finding-4) | `tests/` |
| 5 | **INFO** | confirmed | [Models exist but there are no migrations](#finding-5) | `database/migrations` |
| 6 | **INFO** | confirmed | [No static analysis tool is installed](#finding-6) | `composer.json` |
| 7 | **INFO** | potential | [No continuous integration configuration found](#finding-7) | `project root` |

### <a id="finding-1"></a>1. [HIGH] 1 locked package cannot run on the pinned PHP version

- **Severity:** HIGH (confirmed)
- **Component:** `composer.lock`
- **Analyzer:** dependencies · **Rule:** `deps.php-incompatible`

These packages need a PHP version outside the platform PHP pinned in composer.json (8.2.12). Installing on a matching runtime fails the platform check, or the code fails at runtime.

**Evidence**

```text
- symfony/clock v8.0.1 requires php ">=8.4"
```

**Remediation:** Align the "php" requirement (or config.platform.php) with the runtime actually used, then run "composer update" to re-resolve the lock file.

### <a id="finding-2"></a>2. [MEDIUM] Laravel 11 no longer receives security fixes

- **Severity:** MEDIUM (confirmed)
- **Component:** `composer.lock`
- **Analyzer:** environment · **Rule:** `env.laravel-end-of-life`

Vulnerabilities discovered in this Laravel version will not be patched upstream. This is a maintenance risk, not proof of a current vulnerability.

**Evidence**

```text
Laravel 11 security support ended on 2026-03-12 (https://laravel.com/docs/releases#support-policy).
```

**Remediation:** Plan an upgrade to a supported Laravel release. Upgrade one major version at a time, following the official upgrade guides.

### <a id="finding-3"></a>3. [LOW] .env is world-readable

- **Severity:** LOW (confirmed)
- **Component:** `.env`
- **Analyzer:** security · **Rule:** `sec.env-permissions`

Any local user or process on the server can read the application's credentials.

**Evidence**

```text
Mode 0644
```

**Remediation:** Restrict it to the owner and the web server group, e.g. chmod 640 .env.

### <a id="finding-4"></a>4. [LOW] No automated tests found

- **Severity:** LOW (confirmed)
- **Component:** `tests/`
- **Analyzer:** quality · **Rule:** `quality.no-tests`

Without tests, upgrades (including security updates of Laravel and dependencies) cannot be verified quickly, so they tend to be postponed.

**Evidence**

```text
No *Test.php files under tests/ (Pest is installed).
```

**Remediation:** Start with feature tests for the most important routes (php artisan make:test), and run them in CI.

### <a id="finding-5"></a>5. [INFO] Models exist but there are no migrations

- **Severity:** INFO (confirmed)
- **Component:** `database/migrations`
- **Analyzer:** database · **Rule:** `db.no-migrations`

The schema cannot be recreated from the repository, which makes testing and new environments harder. The schema may be managed elsewhere (a dump file, another repository).

**Evidence**

```text
app/Models contains classes; database/migrations has no PHP files.
```

**Remediation:** Add migrations (or a schema dump via "php artisan schema:dump") so the schema is versioned with the code.

### <a id="finding-6"></a>6. [INFO] No static analysis tool is installed

- **Severity:** INFO (confirmed)
- **Component:** `composer.json`
- **Analyzer:** quality · **Rule:** `quality.no-static-analysis`

Static analysis finds type errors and dead code before they reach production, and makes refactoring safer.

**Evidence**

```text
None of phpstan/phpstan, larastan/larastan or vimeo/psalm is required.
```

**Remediation:** Add Larastan: composer require --dev larastan/larastan, then start at a low level and raise it over time.

### <a id="finding-7"></a>7. [INFO] No continuous integration configuration found

- **Severity:** INFO (potential risk, needs review)
- **Component:** `project root`
- **Analyzer:** quality · **Rule:** `quality.no-ci`

Without CI, tests and checks depend on each developer remembering to run them. CI may be configured outside the repository (e.g. in a hosting platform).

**Evidence**

```text
None of: .github/workflows, .gitlab-ci.yml, bitbucket-pipelines.yml, .circleci/config.yml, Jenkinsfile, azure-pipelines.yml, .travis.yml.
```

**Remediation:** Add a pipeline that runs composer install, the test suite, static analysis, the formatter in check mode and composer audit.

## Environment

| Item | Value | Source |
|---|---|---|
| Laravel version | 11.34.2 | detected (composer.lock) |
| Project layout | Laravel 11+ style (bootstrap/app.php configures routing and middleware) | _inferred_ (bootstrap/app.php, app/Http/Kernel.php) |
| Laravel support status | End of life (security fixes ended 2026-03-12) | detected (https://laravel.com/docs/releases#support-policy) |
| PHP requirement | ^8.2 | detected (composer.json) |
| PHP platform override | 8.2.12 | detected (composer.json config.platform.php) |
| PHP running the inspector | 8.3.32 | detected (~/.local/bin/php-static) |
| Composer version | 2.10.3 | detected (composer --version --no-ansi) |
| Node.js version | 22.23.3 | detected (node --version) |
| npm version | 10.9.9 | detected (npm --version) |
| pnpm version | Not installed | detected (PATH lookup) |
| Yarn version | Not installed | detected (PATH lookup) |
| Bun version | Not installed | detected (PATH lookup) |
| Application environment | local | detected (.env: APP_ENV) |
| Debug mode | true | detected (.env: APP_DEBUG) |
| Database technology | SQLite | detected (config/database.php) |
| Frontend build tool | Vite | detected (vite.config.js) |
| Frontend package manager | npm | detected (package-lock.json) |
| Frontend technologies | Livewire, Alpine.js, Tailwind CSS | detected (package.json, composer.json) |

### Infrastructure drivers

| Setting | Value | Source | Origin |
|---|---|---|---|
| Database connection | sqlite | detected | .env: DB_CONNECTION |
| Cache store | database | detected | .env: CACHE_STORE |
| Queue connection | database | detected | .env: QUEUE_CONNECTION |
| Session driver | database | detected | .env: SESSION_DRIVER |
| Log channel | stack | inferred | Laravel 11 framework default (no config/logging.php) |
| Filesystem disk | local | inferred | Laravel 11 framework default (no config/filesystems.php) |
| Mailer | log | inferred | Laravel 11 framework default (no config/mail.php) |
| Broadcast connection | log | inferred | Laravel 11 framework default (no config/broadcasting.php) |

## Dependencies

| Item | Value | Source |
|---|---|---|
| Direct dependencies | 3 | detected (composer.json require) |
| Direct development dependencies | 2 | detected (composer.json require-dev) |
| composer.lock | Present | detected (composer.lock) |
| Locked packages | 7 (5 production, 2 development) | detected (composer.lock) |
| Installed in vendor/ | Not installed | detected (vendor/composer/installed.json) |
| Minimum stability | stable | detected (composer.json) |

### Platform requirements

| Requirement | Constraint |
|---|---|
| php | ^8.2 |

### Direct dependencies

| Package | Constraint | Locked version | Scope |
|---|---|---|---|
| laravel/framework | ^11.31 | v11.34.2 | production |
| laravel/tinker | ^2.9 | v2.10.0 | production |
| livewire/livewire | ^3.5 | v3.5.12 | production |
| laravel/pint | ^1.13 | v1.18.3 | development |
| pestphp/pest | ^3.0 | v3.5.1 | development |

> Offline mode: security advisories and outdated packages were not checked.

## Routes

| Item | Value | Source |
|---|---|---|
| Route source | Static analysis (application not booted) | detected |
| Route files read | bootstrap/app.php, routes/web.php, routes/api.php | detected |
| Total routes | 16 | _inferred_ (static analysis of route files) |
| Web routes | 9 | _inferred_ (static analysis of route files) |
| API routes | 7 | _inferred_ (static analysis of route files) |
| Named routes | 10 | _inferred_ (static analysis of route files) |
| Closure routes | 2 | _inferred_ (static analysis of route files) |
| Controllers referenced | 4 | _inferred_ (static analysis of route files) |
| Behind authentication middleware | 11 | _inferred_ (static analysis of route files) |
| Signed-URL routes | 0 | _inferred_ (static analysis of route files) |
| Behind custom middleware only | 0 | _inferred_ (middleware not recognised as authentication; may still restrict access) |
| Without authentication middleware | 5 | _inferred_ (static analysis of route files) |

### Routes by HTTP method

| Method | Routes |
|---|---|
| ANY | 1 |
| DELETE | 2 |
| GET | 10 |
| PATCH | 2 |
| POST | 1 |
| PUT | 1 |

### Routes requiring review

_None._

### Route list

| Method | URI | Name | Action | Middleware | Access |
|---|---|---|---|---|---|
| GET | / |  | View: welcome | web | public |
| GET | api/posts | posts.index | Api\PostController@index | api, auth:sanctum | authenticated |
| POST | api/posts | posts.store | Api\PostController@store | api, auth:sanctum | authenticated |
| DELETE | api/posts/{post} | posts.destroy | Api\PostController@destroy | api, auth:sanctum | authenticated |
| GET | api/posts/{post} | posts.show | Api\PostController@show | api, auth:sanctum | authenticated |
| PUT\|PATCH | api/posts/{post} | posts.update | Api\PostController@update | api, auth:sanctum | authenticated |
| GET | api/status |  | Closure | api | public |
| GET | api/user |  | Closure | api, auth:sanctum | authenticated |
| GET | counter |  | App\Livewire\Counter | web | public |
| GET | dashboard | dashboard | View: dashboard | web, auth, verified | authenticated |
| ANY | home |  | Redirect to /dashboard | web | public |
| GET | orders/{order} | orders.show | OrderController@show | web, auth, can:view,order | authenticated |
| DELETE | profile | profile.destroy | ProfileController@destroy | web, auth, verified | authenticated |
| GET | profile | profile.edit | ProfileController@edit | web, auth, verified | authenticated |
| PATCH | profile | profile.update | ProfileController@update | web, auth, verified | authenticated |
| GET | up |  | Health check (framework) |  | public |

> Routes were read from source without booting the application. Routes registered by packages (e.g. Telescope, Horizon, Filament, Sanctum) and routes built dynamically are not included; use --use-artisan to list them.

> Installed packages that register their own routes (not listed above): Livewire.

## Configuration

| Item | Value | Source |
|---|---|---|
| Environment file | .env | detected |
| Environment | local | detected (.env: APP_ENV) |
| Published config files | 2 | detected (config/) |

### Configuration overview

| Area | Setting | Value | Source | Origin |
|---|---|---|---|---|
| Application | Environment | local | detected | .env: APP_ENV |
| Application | Debug mode | true | detected | .env: APP_DEBUG |
| Application | URL | http://localhost | detected | .env: APP_URL |
| Application | Timezone | UTC | detected | config/app.php:timezone |
| Application | Locale | en | inferred | Laravel 11 framework default (locale not set in config/app.php) |
| Application | Cipher | AES-256-CBC | detected | config/app.php:cipher |
| Application | Maintenance driver | file | inferred | Laravel 11 framework default (maintenance.driver not set in config/app.php) |
| Session | Driver | database | detected | .env: SESSION_DRIVER |
| Session | Lifetime (minutes) | 120 | inferred | Laravel 11 framework default (no config/session.php) |
| Session | Encrypted | false | inferred | Laravel 11 framework default (no config/session.php) |
| Session | Secure cookie | null | inferred | Laravel 11 framework default (no config/session.php) |
| Session | HTTP-only cookie | true | inferred | Laravel 11 framework default (no config/session.php) |
| Session | SameSite | lax | inferred | Laravel 11 framework default (no config/session.php) |
| Session | Expire on close | false | inferred | Laravel 11 framework default (no config/session.php) |
| Cache | Default store | database | detected | .env: CACHE_STORE |
| Queue | Default connection | database | detected | .env: QUEUE_CONNECTION |
| Queue | Failed job driver | database-uuids | inferred | Laravel 11 framework default (no config/queue.php) |
| Logging | Default channel | stack | inferred | Laravel 11 framework default (no config/logging.php) |
| Filesystem | Default disk | local | inferred | Laravel 11 framework default (no config/filesystems.php) |
| Authentication | Default guard | web | inferred | Laravel 11 framework default (no config/auth.php) |
| Authentication | Password confirmation timeout (s) | 10800 | inferred | Laravel 11 framework default (no config/auth.php) |
| Hashing | Driver | bcrypt | inferred | Laravel 11 framework default (no config/hashing.php) |
| Hashing | Bcrypt rounds | 12 | inferred | Laravel 11 framework default (no config/hashing.php) |
| Logging | Level | debug | inferred | Laravel default (env('LOG_LEVEL', 'debug')) |
| CORS | Paths | api/*, sanctum/csrf-cookie | inferred | Laravel framework default (no config/cors.php) |
| CORS | Allowed origins | * | inferred | Laravel framework default (no config/cors.php) |
| CORS | Supports credentials | false | inferred | Laravel framework default (no config/cors.php) |
| Application | Application key | Set | detected | .env: APP_KEY (value never read) |

## Database

| Item | Value | Source |
|---|---|---|
| Default connection | sqlite | detected (.env: DB_CONNECTION) |
| Database technology | SQLite | detected (config/database.php) |
| Configured connections | sqlite, mysql, pgsql, legacy | detected (config/database.php) |
| Migration files | 0 | detected (database/migrations) |
| Tables created by migrations | 0 | detected (Schema::create()) |
| Foreign key constraints | 0 | detected (constrained() / foreign()) |
| Explicit indexes | 0 | detected (index() / unique() / primary() / morphs()) |
| Seeders | 0 | detected (database/seeders) |
| Factories | 0 | detected (database/factories) |

### Connections

| Name | Driver |  |
|---|---|---|
| sqlite | sqlite | default |
| mysql | mysql |  |
| pgsql | pgsql |  |
| legacy | sqlsrv |  |

### Tables (from migrations)

_None._

> The database was not connected to. Schema details come from migration files only; tables created by packages or by raw SQL may be missing.

## Security

| Item | Value | Source |
|---|---|---|
| Project rules | 6 | detected |
| Source rules | 4 | detected |
| Files scanned | 14 | detected (app, bootstrap, config, routes) |
| PHP files parsed | 12 | detected |
| Git tracking | Unknown | detected (git ls-files) |

### Security checks with findings

| Rule | Severity | Confidence | Finding |
|---|---|---|---|
| sec.env-permissions | LOW | confirmed | .env is world-readable |

> Git tracking could not be determined (not a git checkout, git unavailable, or the repository is not trusted by git), so "committed file" checks were skipped.

> tests/ is not scanned. Code findings are pattern-based and local to single expressions; they need manual review and do not prove exploitability.

## Architecture

| Item | Value | Source |
|---|---|---|
| Overview | Laravel 11 application using the Laravel 11+ application structure with 4 controllers, 1 model and 16 routes. It serves both web pages and an API. User interface: Livewire (1 component). Business logic appears to live in controllers and models (no service, action or repository layer detected). | _inferred_ (derived from detected classes, packages and routes) |
| Code roots scanned | app | detected (composer.json autoload) |
| Classes, interfaces, traits and enums | 6 | detected |
| Patterns detected | Conventional Laravel MVC | _inferred_ |
| Frontend | Livewire (1 component) | detected |
| Authentication packages | None (framework defaults) | detected (composer.json) |
| API surface | 7 of 16 routes are API routes | _inferred_ (route analysis) |
| Outbound HTTP | 0 Http facade calls, 0 Guzzle clients | detected (static scan) |
| Third-party integrations | None detected | detected (composer.json) |
| Configured services | None | detected (config/services.php) |

### Component inventory

| Component | Count | Examples | Main location |
|---|---|---|---|
| Controllers | 4 | PostController, Controller, OrderController, ProfileController | app/Http/Controllers |
| Livewire components | 1 | Counter | app/Livewire |
| Models | 1 | User | app/Models |

> This overview is inferred from file structure, class inheritance and installed packages. A missing conventional directory does not mean anything is wrong.

## Code Quality

| Item | Value | Source |
|---|---|---|
| Test framework | Pest | detected (composer.json) |
| Test files | 0 (0 feature, 0 unit) | detected (tests/) |
| phpunit.xml | Missing | detected |
| Continuous integration | None detected | detected |
| Checks referenced in CI | None | detected (CI configuration) |

### Quality tools

| Tool | Package | Config | Installed binary |
|---|---|---|---|
| PHPStan | not required | none | no |
| Larastan | not required | n/a | n/a |
| Psalm | not required | none | n/a |
| Laravel Pint | laravel/pint | none | no |
| PHP CS Fixer | not required | none | no |
| PHP_CodeSniffer | not required | none | no |
| Rector | not required | none | n/a |

> Tools were detected but not run. Use --run-quality-tools (or --run-quality-tools=phpstan,pint) to run them; you will be asked to confirm.

## Operations

External commands run during this inspection:

| Time | Purpose | Command | Outcome | Network |
|---|---|---|---|---|
| 2026-11-01T13:29:17+00:00 | Read Composer version | `composer --version --no-ansi` | completed (exit 0) | no |
| 2026-11-01T13:29:17+00:00 | Read Node.js version | `node --version` | completed (exit 0) | no |
| 2026-11-01T13:29:17+00:00 | Read npm version | `npm --version` | completed (exit 0) | no |
| 2026-11-01T13:29:18+00:00 | List files tracked by git (to tell committed files from local ones) | `git -c core.fsmonitor=false -c core.untrackedCache=false -C /path/to/laravel-11 ls-files -z --cached` | completed (exit 128) | no |

---

_Generated by Laravel Project Inspector 0.1.0 on 2026-11-01T13:29:17+00:00._

_Source labels: **detected** = read directly from project files; **inferred** = derived from constraints, defaults or examples; **unknown** = needs runtime evaluation._
