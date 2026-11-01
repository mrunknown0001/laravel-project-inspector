# Laravel Project Inspection: fixture/insecure-app

> The inspected project was not modified. Files were read without executing project code. External commands are listed under Operations.

| | |
|---|---|
| **Project** | fixture/insecure-app |
| **Path** | /path/to/insecure-app |
| **Framework** | Laravel 10.48.4 |
| **Generated** | 2026-11-01 13:29:16 +00:00 |
| **Inspector version** | 0.1.0 |
| **Duration** | 0.4 s |
| **Network checks** | Disabled (offline) |

## Summary

53 findings, including 14 high severity: review soon.

| Severity | Count |
|---|---:|
| CRITICAL | 0 |
| HIGH | 14 |
| MEDIUM | 18 |
| LOW | 14 |
| INFO | 7 |

## Findings

| # | Severity | Confidence | Finding | Component |
|---:|---|---|---|---|
| 1 | **HIGH** | confirmed | [Debug mode is enabled in a production environment](#finding-1) | `.env` |
| 2 | **HIGH** | confirmed | [APP_KEY is not set](#finding-2) | `.env` |
| 3 | **HIGH** | confirmed | [The application key is hardcoded in config/app.php](#finding-3) | `config/app.php` |
| 4 | **HIGH** | confirmed | [CORS allows any origin together with credentials](#finding-4) | `config/cors.php` |
| 5 | **HIGH** | confirmed | [Laravel Debugbar is enabled in production](#finding-5) | `.env` |
| 6 | **HIGH** | potential | [Public directory contains 1 database file or dump](#finding-6) | `public/` |
| 7 | **HIGH** | potential | [Public directory contains 1 debug or admin script](#finding-7) | `public/` |
| 8 | **HIGH** | confirmed | [A public route calls phpinfo()](#finding-8) | `routes/` |
| 9 | **HIGH** | potential | [1 route closure runs dangerous operations without authentication](#finding-9) | `routes/` |
| 10 | **HIGH** | confirmed | [CSRF protection is disabled for all routes](#finding-10) | `app/Http/Middleware/VerifyCsrfToken.php` |
| 11 | **HIGH** | confirmed | [1 value matches a known credential format](#finding-11) | `source code` |
| 12 | **HIGH** | potential | [Shell command built with request input (1 occurrence)](#finding-12) | `source code` |
| 13 | **HIGH** | potential | [Raw SQL built with request input (1 occurrence)](#finding-13) | `source code` |
| 14 | **HIGH** | potential | [Request input is echoed without escaping](#finding-14) | `resources/views` |
| 15 | **MEDIUM** | confirmed | [Laravel 10 no longer receives security fixes](#finding-15) | `composer.lock` |
| 16 | **MEDIUM** | confirmed | [Session driver is "array" in production](#finding-16) | `.env` |
| 17 | **MEDIUM** | confirmed | [.env.example contains an application key](#finding-17) | `.env.example` |
| 18 | **MEDIUM** | confirmed | [Session cookie is readable by JavaScript](#finding-18) | `config/session.php` |
| 19 | **MEDIUM** | potential | [SameSite=None session cookie without the Secure flag](#finding-19) | `config/session.php` |
| 20 | **MEDIUM** | confirmed | [Bcrypt work factor is 4 in production](#finding-20) | `.env` |
| 21 | **MEDIUM** | confirmed | [Laravel Debugbar is a production dependency](#finding-21) | `composer.json` |
| 22 | **MEDIUM** | confirmed | [.env is world-readable](#finding-22) | `.env` |
| 23 | **MEDIUM** | potential | [4 routes with sensitive-looking paths have no authentication middleware](#finding-23) | `routes/` |
| 24 | **MEDIUM** | confirmed | [3 secrets are hardcoded](#finding-24) | `source code` |
| 25 | **MEDIUM** | potential | [1 env() default contains a secret](#finding-25) | `source code` |
| 26 | **MEDIUM** | confirmed | [1 URL embeds a password](#finding-26) | `source code` |
| 27 | **MEDIUM** | potential | [1 secret-looking value in an env file other than .env](#finding-27) | `source code` |
| 28 | **MEDIUM** | potential | [Unsafe unserialize() of non-literal data (1 occurrence)](#finding-28) | `source code` |
| 29 | **MEDIUM** | potential | [Raw SQL assembled by concatenation (1 occurrence)](#finding-29) | `source code` |
| 30 | **MEDIUM** | potential | [File or URL access using request input (1 occurrence)](#finding-30) | `source code` |
| 31 | **MEDIUM** | potential | [Fast hash (md5/sha1) applied to a password (1 occurrence)](#finding-31) | `source code` |
| 32 | **MEDIUM** | potential | [Mass-assignment protection is disabled globally](#finding-32) | `source code` |
| 33 | **LOW** | confirmed | [1 route is registered more than once](#finding-33) | `routes/` |
| 34 | **LOW** | potential | [1 controller referenced by routes could not be found](#finding-34) | `routes/` |
| 35 | **LOW** | confirmed | [Queued jobs run synchronously in production](#finding-35) | `.env` |
| 36 | **LOW** | confirmed | [Log level is "debug" in production](#finding-36) | `.env` |
| 37 | **LOW** | potential | [2 key columns may be missing an index](#finding-37) | `database/migrations` |
| 38 | **LOW** | potential | [Session cookie is not marked Secure in production](#finding-38) | `config/session.php` |
| 39 | **LOW** | potential | [1 environment file variant present in the project root](#finding-39) | `project root` |
| 40 | **LOW** | potential | [1 API endpoint changes data without authentication middleware](#finding-40) | `routes/api.php` |
| 41 | **LOW** | potential | [All proxies are trusted](#finding-41) | `app/Http/Middleware/TrustProxies.php` |
| 42 | **LOW** | potential | [Redirect to a URL from request input (1 occurrence)](#finding-42) | `source code` |
| 43 | **LOW** | potential | [Model writes with all request input (1 occurrence)](#finding-43) | `source code` |
| 44 | **LOW** | potential | [1 model disables mass-assignment protection](#finding-44) | `app/Models` |
| 45 | **LOW** | potential | [1 Blade expression prints unescaped HTML](#finding-45) | `resources/views` |
| 46 | **LOW** | confirmed | [No automated tests found](#finding-46) | `tests/` |
| 47 | **INFO** | confirmed | [6 variables in .env are missing from .env.example](#finding-47) | `.env.example` |
| 48 | **INFO** | potential | [1 column is declared with foreignId() but no constraint](#finding-48) | `database/migrations` |
| 49 | **INFO** | confirmed | [1 migration statement runs raw SQL](#finding-49) | `database/migrations` |
| 50 | **INFO** | potential | [Debug statement left in application code (1 occurrence)](#finding-50) | `source code` |
| 51 | **INFO** | confirmed | [No static analysis tool is installed](#finding-51) | `composer.json` |
| 52 | **INFO** | confirmed | [No code style tool is installed](#finding-52) | `composer.json` |
| 53 | **INFO** | potential | [No continuous integration configuration found](#finding-53) | `project root` |

### <a id="finding-1"></a>1. [HIGH] Debug mode is enabled in a production environment

- **Severity:** HIGH (confirmed)
- **Component:** `.env`
- **Analyzer:** security · **Rule:** `sec.debug-in-production`

With APP_DEBUG=true, error pages show stack traces, configuration values and environment variables, including credentials, to anyone who triggers an error. Older debug page versions have also had remote code execution vulnerabilities.

**Evidence**

```text
APP_ENV=production (.env: APP_ENV); APP_DEBUG=true (.env: APP_DEBUG).
```

**Remediation:** Set APP_DEBUG=false in the production environment and run "php artisan config:cache" after deploying.

### <a id="finding-2"></a>2. [HIGH] APP_KEY is not set

- **Severity:** HIGH (confirmed)
- **Component:** `.env`
- **Analyzer:** security · **Rule:** `sec.app-key-missing`

Laravel uses APP_KEY to encrypt cookies, sessions and encrypted model attributes. Without it, encryption fails. A key added later invalidates existing encrypted data.

**Evidence**

```text
APP_KEY is defined in .env but empty.
```

**Remediation:** Generate a key with "php artisan key:generate" in each environment, and store it in the environment rather than in code.

### <a id="finding-3"></a>3. [HIGH] The application key is hardcoded in config/app.php

- **Severity:** HIGH (confirmed)
- **Component:** `config/app.php`
- **Analyzer:** security · **Rule:** `sec.app-key-hardcoded`

A key written into a committed config file is shared by every environment and everyone with access to the repository.

**Evidence**

```text
'key' => base**** (51 characters)
```

**Remediation:** Use 'key' =&gt; env('APP_KEY'), rotate the exposed key, and set APP_KEY per environment.

### <a id="finding-4"></a>4. [HIGH] CORS allows any origin together with credentials

- **Severity:** HIGH (confirmed)
- **Component:** `config/cors.php`
- **Analyzer:** security · **Rule:** `sec.cors-wildcard-credentials`

With supports_credentials enabled, Laravel reflects the requesting origin when allowed_origins is "*". Any website could then make authenticated requests with the user's cookies and read the responses.

**Evidence**

```text
'allowed_origins' => ['*'], 'supports_credentials' => true (config/cors.php:supports_credentials)
```

**Remediation:** List the exact front-end origins in allowed_origins (or allowed_origins_patterns) when credentials are required.

### <a id="finding-5"></a>5. [HIGH] Laravel Debugbar is enabled in production

- **Severity:** HIGH (confirmed)
- **Component:** `.env`
- **Analyzer:** security · **Rule:** `sec.debug-tool-enabled-in-production`

Debugbar renders queries, session data and request details into every page.

**Evidence**

```text
APP_ENV=production (.env: APP_ENV); DEBUGBAR_ENABLED=true
```

**Remediation:** Set DEBUGBAR_ENABLED=false in production.

### <a id="finding-6"></a>6. [HIGH] Public directory contains 1 database file or dump

- **Severity:** HIGH (potential risk, needs review)
- **Component:** `public/`
- **Analyzer:** security · **Rule:** `sec.public-sensitive-file`

Everything under public/ can be downloaded by visitors unless the web server blocks it. A database file or dump there may expose source code, data or credentials.

**Evidence**

```text
- public/backup.sql
```

**Remediation:** Remove these files from public/ (and from the server), and keep backups and dumps outside the web root.

### <a id="finding-7"></a>7. [HIGH] Public directory contains 1 debug or admin script

- **Severity:** HIGH (potential risk, needs review)
- **Component:** `public/`
- **Analyzer:** security · **Rule:** `sec.public-sensitive-file`

Everything under public/ can be downloaded by visitors unless the web server blocks it. A debug or admin script there may expose source code, data or credentials.

**Evidence**

```text
- public/phpinfo.php
```

**Remediation:** Remove these files from public/ (and from the server), and keep backups and dumps outside the web root.

### <a id="finding-8"></a>8. [HIGH] A public route calls phpinfo()

- **Severity:** HIGH (confirmed)
- **Component:** `routes/`
- **Analyzer:** security · **Rule:** `sec.route-phpinfo`

phpinfo() prints the PHP configuration, server paths and all environment variables, which in Laravel include database passwords and APP_KEY.

**Evidence**

```text
- GET phpinfo calls phpinfo() (exposes PHP configuration and environment variables), routes/web.php:8
```

**Remediation:** Remove the route. If PHP configuration must be inspected, do it from the command line.

### <a id="finding-9"></a>9. [HIGH] 1 route closure runs dangerous operations without authentication

- **Severity:** HIGH (potential risk, needs review)
- **Component:** `routes/`
- **Analyzer:** security · **Rule:** `sec.route-dangerous-closure`

These closures run commands, raw SQL, file writes or debug dumps, and no authentication middleware was found on the route. Anyone who can reach the URL can trigger them.

**Evidence**

```text
- GET debug/run calls Artisan::call() (runs Artisan commands), routes/web.php:11
```

**Remediation:** Remove debug and maintenance routes, or move the logic to Artisan commands. If they must stay, protect them with authentication and authorization middleware.

### <a id="finding-10"></a>10. [HIGH] CSRF protection is disabled for all routes

- **Severity:** HIGH (confirmed)
- **Component:** `app/Http/Middleware/VerifyCsrfToken.php`
- **Analyzer:** security · **Rule:** `sec.csrf-disabled`

The CSRF exclusion list contains a wildcard that matches every route. Any website a logged-in user visits can submit forms to the application on their behalf.

**Evidence**

```text
Excluded URIs: *
```

**Remediation:** Remove the wildcard and exclude only specific endpoints that verify requests another way (e.g. signed webhooks).

### <a id="finding-11"></a>11. [HIGH] 1 value matches a known credential format

- **Severity:** HIGH (confirmed)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.secret-token`

These values match the format of real API keys, tokens or private keys. Anyone with access to the code can use them, and once committed they stay in the git history.

**Evidence**

```text
- config/services.php:12: NoTokenLimit API key ntl_**** (29 characters)
```

**Remediation:** Revoke and rotate each credential, move it to the environment (.env / secret manager), and read it through config().

### <a id="finding-12"></a>12. [HIGH] Shell command built with request input (1 occurrence)

- **Severity:** HIGH (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.command-injection`

A shell command contains request input. If the input is not strictly validated, attackers can run arbitrary commands on the server.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:17: exec('convert ' . $request->input('file') . ' out.pdf');
```

**Remediation:** Avoid shell commands for user-facing features. Otherwise pass arguments with Symfony Process arrays or escapeshellarg(), and validate input against an allow-list.

### <a id="finding-13"></a>13. [HIGH] Raw SQL built with request input (1 occurrence)

- **Severity:** HIGH (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.sql-injection`

Request input is concatenated or passed directly into a raw SQL expression, which allows SQL injection.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:14: $rows = DB::select("SELECT * FROM orders WHERE status = '" . $request->input('status') . "'");
```

**Remediation:** Use query builder methods or bindings: whereRaw("email = ?", [$email]); never concatenate input into SQL.

### <a id="finding-14"></a>14. [HIGH] Request input is echoed without escaping

- **Severity:** HIGH (potential risk, needs review)
- **Component:** `resources/views`
- **Analyzer:** security · **Rule:** `sec.blade-unescaped-input`

{!! !!} prints raw HTML. Echoing request input this way allows reflected cross-site scripting.

**Evidence**

```text
- resources/views/comments.blade.php:4: {!! request('q') !!}
```

**Remediation:** Use {{ }} (escaped output) for anything derived from input.

### <a id="finding-15"></a>15. [MEDIUM] Laravel 10 no longer receives security fixes

- **Severity:** MEDIUM (confirmed)
- **Component:** `composer.lock`
- **Analyzer:** environment · **Rule:** `env.laravel-end-of-life`

Vulnerabilities discovered in this Laravel version will not be patched upstream. This is a maintenance risk, not proof of a current vulnerability.

**Evidence**

```text
Laravel 10 security support ended on 2025-02-04 (https://laravel.com/docs/releases#support-policy).
```

**Remediation:** Plan an upgrade to a supported Laravel release. Upgrade one major version at a time, following the official upgrade guides.

### <a id="finding-16"></a>16. [MEDIUM] Session driver is "array" in production

- **Severity:** MEDIUM (confirmed)
- **Component:** `.env`
- **Analyzer:** configuration · **Rule:** `config.production-array-session`

The array driver keeps data only for the current request. Cached values are lost immediately, and with sessions users cannot stay logged in.

**Evidence**

```text
APP_ENV=production (.env: APP_ENV); session driver "array" (.env: SESSION_DRIVER).
```

**Remediation:** Use a persistent driver (redis, database, file, memcached) in production.

### <a id="finding-17"></a>17. [MEDIUM] .env.example contains an application key

- **Severity:** MEDIUM (confirmed)
- **Component:** `.env.example`
- **Analyzer:** security · **Rule:** `sec.app-key-in-example`

Example env files are normally committed. A real key there may be copied into environments, and anyone with the repository could decrypt their data.

**Evidence**

```text
APP_KEY in .env.example is not empty.
```

**Remediation:** Leave APP_KEY empty in .env.example and generate a unique key per environment with "php artisan key:generate". Rotate any key that has been shared.

### <a id="finding-18"></a>18. [MEDIUM] Session cookie is readable by JavaScript

- **Severity:** MEDIUM (confirmed)
- **Component:** `config/session.php`
- **Analyzer:** security · **Rule:** `sec.session-not-http-only`

With http_only disabled, any cross-site scripting flaw can steal session cookies and take over accounts.

**Evidence**

```text
session.http_only = false (config/session.php:http_only)
```

**Remediation:** Set 'http_only' =&gt; true in config/session.php (the Laravel default).

### <a id="finding-19"></a>19. [MEDIUM] SameSite=None session cookie without the Secure flag

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `config/session.php`
- **Analyzer:** security · **Rule:** `sec.session-same-site-none`

SameSite=None sends the session cookie on cross-site requests, which weakens CSRF protection. Browsers reject SameSite=None cookies that are not also Secure.

**Evidence**

```text
same_site = none (config/session.php:same_site); secure = null (config/session.php:secure (env('SESSION_SECURE_COOKIE') unset, no default))
```

**Remediation:** Use SameSite "lax" (the default) unless cross-site embedding is required; if it is, set SESSION_SECURE_COOKIE=true.

### <a id="finding-20"></a>20. [MEDIUM] Bcrypt work factor is 4 in production

- **Severity:** MEDIUM (confirmed)
- **Component:** `.env`
- **Analyzer:** security · **Rule:** `sec.weak-bcrypt-rounds`

A low bcrypt cost makes stolen password hashes much faster to crack. Low values are normal in tests, not in production.

**Evidence**

```text
APP_ENV=production (.env: APP_ENV); bcrypt rounds = 4 (.env: BCRYPT_ROUNDS)
```

**Remediation:** Use at least 12 rounds (the Laravel default) in production; keep low values only in phpunit.xml / .env.testing.

### <a id="finding-21"></a>21. [MEDIUM] Laravel Debugbar is a production dependency

- **Severity:** MEDIUM (confirmed)
- **Component:** `composer.json`
- **Analyzer:** security · **Rule:** `sec.debug-package-in-production`

Laravel Debugbar is listed under "require", so it is installed in production. Debugging tools expose queries, request data and configuration when enabled by mistake.

**Evidence**

```text
"require": {"barryvdh/laravel-debugbar": "^3.9"}
```

**Remediation:** Move barryvdh/laravel-debugbar to "require-dev" (composer require --dev barryvdh/laravel-debugbar) so production installs with --no-dev exclude it.

### <a id="finding-22"></a>22. [MEDIUM] .env is world-readable

- **Severity:** MEDIUM (confirmed)
- **Component:** `.env`
- **Analyzer:** security · **Rule:** `sec.env-permissions`

Any local user or process on the server can read the application's credentials.

**Evidence**

```text
Mode 0644
```

**Remediation:** Restrict it to the owner and the web server group, e.g. chmod 640 .env.

### <a id="finding-23"></a>23. [MEDIUM] 4 routes with sensitive-looking paths have no authentication middleware

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `routes/`
- **Analyzer:** security · **Rule:** `sec.route-sensitive-public`

The path suggests privileged functionality (admin, settings, export, ...) and no authentication middleware was found at the route, group or controller level. The controller may still check access itself; verify each one.

**Evidence**

```text
- DELETE api/users/{id} ("users"; middleware: api), routes/api.php:7
- GET admin/users ("admin"; middleware: web), routes/web.php:9
- GET admin/users ("admin"; middleware: web), routes/web.php:10
- GET settings ("settings"; middleware: web), routes/web.php:15
```

**Remediation:** Add auth middleware (and authorization such as can: or policies) to the route group, or confirm the endpoint is intentionally public.

### <a id="finding-24"></a>24. [MEDIUM] 3 secrets are hardcoded

- **Severity:** MEDIUM (confirmed)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.secret-literal`

Passwords, secrets or API keys are written as string literals. Even when the value is only used locally, committed credentials tend to be reused in other environments.

**Evidence**

```text
- app/Services/PaymentService.php:7: 'apiToken' => tok_**** (21 characters)
- config/services.php:6: 'secret' => Live**** (24 characters)
- resources/js/app.js:1: stripeSecret = Fron**** (22 characters)
```

**Remediation:** Replace the literal with env('NAME') in a config file, set the value in each environment, and rotate it if it was ever real.

### <a id="finding-25"></a>25. [MEDIUM] 1 env() default contains a secret

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.secret-env-default`

env('X', 'value') falls back to the literal when the variable is missing, so the 'default' quietly becomes the production credential if an environment is misconfigured.

**Evidence**

```text
- config/services.php:9: env('MAILGUN_SECRET', mg-D**** (21 characters))
```

**Remediation:** Remove the default (env('DB_PASSWORD')) so a missing variable fails loudly, and rotate the value if it is real.

### <a id="finding-26"></a>26. [MEDIUM] 1 URL embeds a password

- **Severity:** MEDIUM (confirmed)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.secret-url-credentials`

Connection strings such as redis://user:[REDACTED]@host include the password in source code and in logs that print the URL.

**Evidence**

```text
- config/services.php:14: redis://default:Redi**** (13 characters)@...
```

**Remediation:** Build the URL from environment variables or pass the password separately.

### <a id="finding-27"></a>27. [MEDIUM] 1 secret-looking value in an env file other than .env

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.secret-in-env-variant`

Env files other than .env (.env.example, .env.testing, backups) are often committed, copied or deployed. Real-looking values for sensitive keys there are likely live credentials.

**Evidence**

```text
- .env.backup:2: Old-**** (19 characters) (DB_PASSWORD)
```

**Remediation:** Replace the values with placeholders (or delete backup copies) and rotate any real credentials.

### <a id="finding-28"></a>28. [MEDIUM] Unsafe unserialize() of non-literal data (1 occurrence)

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.unsafe-unserialize`

unserialize() on attacker-controlled data can instantiate arbitrary classes and trigger gadget chains, leading to remote code execution.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:18: $cart = unserialize($request->cookie('cart'));
```

**Remediation:** Use json_decode() for data from outside, or pass ['allowed_classes' =&gt; false] (or a list of safe classes).

### <a id="finding-29"></a>29. [MEDIUM] Raw SQL assembled by concatenation (1 occurrence)

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.raw-sql-concatenation`

Raw SQL strings are built by concatenating or interpolating variables. If any of them can come from a user, this is SQL injection.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:16: $users = User::query()->whereRaw("email = '$email'")->get();
```

**Remediation:** Use ? placeholders with bindings, or query builder methods, instead of string building.

### <a id="finding-30"></a>30. [MEDIUM] File or URL access using request input (1 occurrence)

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.path-or-url-from-input`

A file path or URL comes directly from the request. This can allow path traversal (reading or deleting arbitrary files) or server-side request forgery.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:19: $remote = file_get_contents($request->input('url'));
```

**Remediation:** Validate input against an allow-list, use basename() / Storage paths you construct yourself, and restrict outbound hosts.

### <a id="finding-31"></a>31. [MEDIUM] Fast hash (md5/sha1) applied to a password (1 occurrence)

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.weak-password-hash`

MD5 and SHA-1 are fast hashes and are unsuitable for passwords; hashes can be cracked quickly after a breach.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:21: $hash = md5($request->password);
```

**Remediation:** Use Hash::make() / bcrypt() / Argon2id via Laravel's Hash facade, and rehash on login.

### <a id="finding-32"></a>32. [MEDIUM] Mass-assignment protection is disabled globally

- **Severity:** MEDIUM (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.model-unguard`

Model::unguard() turns off $fillable/$guarded for every model. Any code that passes request input to create() or update() can then set any column. It is safe only inside seeders or tests.

**Evidence**

```text
- app/Providers/AppServiceProvider.php:12: Model::unguard()
```

**Remediation:** Remove Model::unguard() from application code (keep it in seeders), and use $fillable on models.

### <a id="finding-33"></a>33. [LOW] 1 route is registered more than once

- **Severity:** LOW (confirmed)
- **Component:** `routes/`
- **Analyzer:** routes · **Rule:** `routes.duplicate`

When two routes share a method and URI, the later definition silently replaces the earlier one, so one of them is unreachable.

**Evidence**

```text
- GET admin/users: routes/web.php:10 overrides routes/web.php:9
```

**Remediation:** Remove or rename one of each pair of duplicate routes.

### <a id="finding-34"></a>34. [LOW] 1 controller referenced by routes could not be found

- **Severity:** LOW (potential risk, needs review)
- **Component:** `routes/`
- **Analyzer:** routes · **Rule:** `routes.missing-controller`

The controller class does not exist at the path its namespace maps to under composer.json PSR-4 rules. Requests to these routes would fail, unless the class is loaded some other way (classmap, custom autoloader).

**Evidence**

```text
- App\Http\Controllers\SettingsController (used by GET settings, routes/web.php:15)
```

**Remediation:** Create the controller, fix the namespace in the route definition, or remove the route.

### <a id="finding-35"></a>35. [LOW] Queued jobs run synchronously in production

- **Severity:** LOW (confirmed)
- **Component:** `.env`
- **Analyzer:** configuration · **Rule:** `config.production-sync-queue`

With the "sync" queue connection, queued jobs, mail and notifications run inside the web request. This slows responses, and a failing job fails the request.

**Evidence**

```text
APP_ENV=production (.env: APP_ENV); queue connection "sync" (.env: QUEUE_CONNECTION).
```

**Remediation:** Use a real queue backend (database, redis, sqs) in production and run a queue worker.

### <a id="finding-36"></a>36. [LOW] Log level is "debug" in production

- **Severity:** LOW (confirmed)
- **Component:** `.env`
- **Analyzer:** configuration · **Rule:** `config.production-debug-logging`

Debug-level logging in production produces large logs and is more likely to record request data, queries or tokens.

**Evidence**

```text
APP_ENV=production (.env: APP_ENV); log level "debug" (.env: LOG_LEVEL).
```

**Remediation:** Set LOG_LEVEL to "info", "warning" or "error" in the production environment.

### <a id="finding-37"></a>37. [LOW] 2 key columns may be missing an index

- **Severity:** LOW (potential risk, needs review)
- **Component:** `database/migrations`
- **Analyzer:** database · **Rule:** `db.missing-index`

Columns named *_id are usually used in joins and WHERE clauses. These have no index or foreign key constraint in any migration, so lookups on them scan the whole table as it grows. Indexes added outside migrations are not visible.

**Evidence**

```text
- orders.user_id (unsignedbiginteger) database/migrations/2024_01_01_000000_create_orders_table.php:14
- orders.coupon_id (foreignid) database/migrations/2024_01_01_000000_create_orders_table.php:15
```

**Remediation:** Add -&gt;index() (or use foreignId()-&gt;constrained()) for columns used in queries, in a new migration.

### <a id="finding-38"></a>38. [LOW] Session cookie is not marked Secure in production

- **Severity:** LOW (potential risk, needs review)
- **Component:** `config/session.php`
- **Analyzer:** security · **Rule:** `sec.session-cookie-not-secure`

The application is served over HTTPS but the session cookie may also be sent over plain HTTP, where it can be intercepted (e.g. on a downgraded or mixed-content request).

**Evidence**

```text
APP_ENV=production (.env: APP_ENV); APP_URL=https://shop.example.test; session.secure = null (config/session.php:secure (env('SESSION_SECURE_COOKIE') unset, no default))
```

**Remediation:** Set SESSION_SECURE_COOKIE=true in the production environment.

### <a id="finding-39"></a>39. [LOW] 1 environment file variant present in the project root

- **Severity:** LOW (potential risk, needs review)
- **Component:** `project root`
- **Analyzer:** security · **Rule:** `sec.env-variant-present`

Copies of .env (production settings, backups) often contain real credentials and are easy to deploy or commit by accident. Whether they are tracked by git could not be determined.

**Evidence**

```text
- .env.backup
```

**Remediation:** Make sure these files are git-ignored and excluded from deployments, or delete them.

### <a id="finding-40"></a>40. [LOW] 1 API endpoint changes data without authentication middleware

- **Severity:** LOW (potential risk, needs review)
- **Component:** `routes/api.php`
- **Analyzer:** security · **Rule:** `sec.route-api-write-public`

POST/PUT/PATCH/DELETE API routes without authentication middleware can be called by anyone. This is sometimes intended (sign-up, contact forms, webhooks with signature checks), so review each route.

**Evidence**

```text
- POST api/orders/{order}/refund -> OrderController@refund, routes/api.php:6
```

**Remediation:** Protect the routes with auth:sanctum (or another guard), or document why they are public and how they are protected (signatures, rate limiting).

### <a id="finding-41"></a>41. [LOW] All proxies are trusted

- **Severity:** LOW (potential risk, needs review)
- **Component:** `app/Http/Middleware/TrustProxies.php`
- **Analyzer:** security · **Rule:** `sec.trust-all-proxies`

Trusting every proxy makes Laravel believe the X-Forwarded-For/-Proto/-Host headers of any request. That is correct only behind a load balancer that strips client-supplied headers. Otherwise clients can spoof their IP address (bypassing IP-based rate limits and logs) or the scheme and host.

**Evidence**

```text
proxies = *
```

**Remediation:** List the load balancer's addresses explicitly, or confirm that the application is reachable only through a proxy that overwrites these headers.

### <a id="finding-42"></a>42. [LOW] Redirect to a URL from request input (1 occurrence)

- **Severity:** LOW (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.open-redirect`

Redirecting to a user-supplied URL lets attackers craft links on your domain that forward victims to phishing sites.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:25: return redirect($request->input('next'));
```

**Remediation:** Redirect only to relative paths or an allow-list of hosts (e.g. url()-&gt;previous() or named routes).

### <a id="finding-43"></a>43. [LOW] Model writes with all request input (1 occurrence)

- **Severity:** LOW (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.mass-assignment-request-all`

Passing $request-&gt;all() to create/update/fill relies entirely on the model's $fillable/$guarded configuration to block privileged columns.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:20: User::create($request->all());
```

**Remediation:** Use $request-&gt;validated() from a Form Request, or $request-&gt;only([...]) with the expected fields.

### <a id="finding-44"></a>44. [LOW] 1 model disables mass-assignment protection

- **Severity:** LOW (potential risk, needs review)
- **Component:** `app/Models`
- **Analyzer:** security · **Rule:** `sec.model-guarded-empty`

With $guarded = [] every attribute can be mass-assigned. This is safe only if the model never receives unfiltered input (e.g. $request-&gt;all()).

**Evidence**

```text
- app/Models/User.php:9: User: protected $guarded = [];
```

**Remediation:** Declare $fillable with the attributes users may set, or make sure only validated data reaches these models.

### <a id="finding-45"></a>45. [LOW] 1 Blade expression prints unescaped HTML

- **Severity:** LOW (potential risk, needs review)
- **Component:** `resources/views`
- **Analyzer:** security · **Rule:** `sec.blade-unescaped-output`

{!! !!} bypasses escaping. That is fine for HTML the application generates itself, but stored user content (comments, profile fields, rich text) printed this way allows stored XSS.

**Evidence**

```text
- resources/views/comments.blade.php:3: {!! $comment->body !!}
```

**Remediation:** Use {{ }} unless the value is trusted HTML. For user-supplied rich text, sanitise it on save (e.g. with an HTML purifier) before printing it raw.

### <a id="finding-46"></a>46. [LOW] No automated tests found

- **Severity:** LOW (confirmed)
- **Component:** `tests/`
- **Analyzer:** quality · **Rule:** `quality.no-tests`

Without tests, upgrades (including security updates of Laravel and dependencies) cannot be verified quickly, so they tend to be postponed.

**Evidence**

```text
No *Test.php files under tests/ (PHPUnit is installed).
```

**Remediation:** Start with feature tests for the most important routes (php artisan make:test), and run them in CI.

### <a id="finding-47"></a>47. [INFO] 6 variables in .env are missing from .env.example

- **Severity:** INFO (confirmed)
- **Component:** `.env.example`
- **Analyzer:** configuration · **Rule:** `config.env-keys-not-in-example`

Variables used locally but absent from .env.example are easy to forget when deploying or onboarding. Only variable names are compared; values are never read.

**Evidence**

```text
- APP_URL
- SESSION_DRIVER
- QUEUE_CONNECTION
- LOG_LEVEL
- BCRYPT_ROUNDS
- DEBUGBAR_ENABLED
```

**Remediation:** Add the missing variable names to .env.example with placeholder values.

### <a id="finding-48"></a>48. [INFO] 1 column is declared with foreignId() but no constraint

- **Severity:** INFO (potential risk, needs review)
- **Component:** `database/migrations`
- **Analyzer:** database · **Rule:** `db.unconstrained-foreign-id`

foreignId() alone only creates an unsigned big integer column. Without -&gt;constrained() the database does not enforce that the referenced row exists. This may be intentional, e.g. for polymorphic or cross-database references.

**Evidence**

```text
- orders.coupon_id database/migrations/2024_01_01_000000_create_orders_table.php:15
```

**Remediation:** Add -&gt;constrained() (with -&gt;cascadeOnDelete() or -&gt;nullOnDelete() as appropriate) where referential integrity is wanted.

### <a id="finding-49"></a>49. [INFO] 1 migration statement runs raw SQL

- **Severity:** INFO (confirmed)
- **Component:** `database/migrations`
- **Analyzer:** database · **Rule:** `db.raw-sql-migrations`

Raw SQL in migrations is often database-specific and is invisible to this analysis. Review it when changing database engines.

**Evidence**

```text
- database/migrations/2024_01_01_000000_create_orders_table.php:23
```

**Remediation:** Prefer schema builder methods where possible, and document engine-specific statements.

### <a id="finding-50"></a>50. [INFO] Debug statement left in application code (1 occurrence)

- **Severity:** INFO (potential risk, needs review)
- **Component:** `source code`
- **Analyzer:** security · **Rule:** `sec.debug-statement`

dd(), dump() and var_dump() print internal data to the response and halt or disturb normal output when reached in production.

**Evidence**

```text
- app/Http/Controllers/ReportController.php:23: dd($rows);
```

**Remediation:** Remove debugging calls before deploying; use logging for diagnostics.

### <a id="finding-51"></a>51. [INFO] No static analysis tool is installed

- **Severity:** INFO (confirmed)
- **Component:** `composer.json`
- **Analyzer:** quality · **Rule:** `quality.no-static-analysis`

Static analysis finds type errors and dead code before they reach production, and makes refactoring safer.

**Evidence**

```text
None of phpstan/phpstan, larastan/larastan or vimeo/psalm is required.
```

**Remediation:** Add Larastan: composer require --dev larastan/larastan, then start at a low level and raise it over time.

### <a id="finding-52"></a>52. [INFO] No code style tool is installed

- **Severity:** INFO (confirmed)
- **Component:** `composer.json`
- **Analyzer:** quality · **Rule:** `quality.no-code-style`

A formatter keeps diffs focused on behaviour and avoids style discussions in reviews.

**Evidence**

```text
None of laravel/pint, friendsofphp/php-cs-fixer or squizlabs/php_codesniffer is required.
```

**Remediation:** Add Laravel Pint (composer require --dev laravel/pint) and run it in CI with --test.

### <a id="finding-53"></a>53. [INFO] No continuous integration configuration found

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
| Laravel version | 10.48.4 | detected (composer.lock) |
| Project layout | Classic (HTTP/Console kernels) | _inferred_ (bootstrap/app.php, app/Http/Kernel.php) |
| Laravel support status | End of life (security fixes ended 2025-02-04) | detected (https://laravel.com/docs/releases#support-policy) |
| PHP requirement | ^8.1 | detected (composer.json) |
| PHP running the inspector | 8.3.32 | detected (~/.local/bin/php-static) |
| Composer version | 2.10.3 | detected (composer --version --no-ansi) |
| Node.js version | 22.23.3 | detected (node --version) |
| npm version | 10.9.9 | detected (npm --version) |
| pnpm version | Not installed | detected (PATH lookup) |
| Yarn version | Not installed | detected (PATH lookup) |
| Bun version | Not installed | detected (PATH lookup) |
| Application environment | production | detected (.env: APP_ENV) |
| Debug mode | true | detected (.env: APP_DEBUG) |
| Database technology | MySQL | detected (config/database.php) |
| Frontend build tool | None detected | detected |
| Frontend package manager | None detected | detected |
| Frontend technologies | Blade only | detected (package.json, composer.json) |

### Infrastructure drivers

| Setting | Value | Source | Origin |
|---|---|---|---|
| Database connection | mysql | detected | .env: DB_CONNECTION |
| Cache store | file | inferred | config/cache.php:default (env('CACHE_DRIVER') default) |
| Queue connection | sync | detected | .env: QUEUE_CONNECTION |
| Session driver | array | detected | .env: SESSION_DRIVER |
| Log channel | stack | inferred | config/logging.php:default (env('LOG_CHANNEL') default) |
| Filesystem disk | unknown | unknown | config/filesystems.php:default |
| Mailer | unknown | unknown | config/mail.php:default |
| Broadcast connection | unknown | unknown | config/broadcasting.php:default |

## Dependencies

| Item | Value | Source |
|---|---|---|
| Direct dependencies | 3 | detected (composer.json require) |
| Direct development dependencies | 1 | detected (composer.json require-dev) |
| composer.lock | Present | detected (composer.lock) |
| Locked packages | 4 (3 production, 1 development) | detected (composer.lock) |
| Installed in vendor/ | Not installed | detected (vendor/composer/installed.json) |
| Minimum stability | stable | detected (composer.json) |

### Platform requirements

| Requirement | Constraint |
|---|---|
| php | ^8.1 |

### Direct dependencies

| Package | Constraint | Locked version | Scope |
|---|---|---|---|
| barryvdh/laravel-debugbar | ^3.9 | v3.9.2 | production |
| guzzlehttp/guzzle | ^7.2 | 7.8.1 | production |
| laravel/framework | ^10.10 | v10.48.4 | production |
| phpunit/phpunit | ^10.1 | 10.5.11 | development |

> Offline mode: security advisories and outdated packages were not checked.

## Routes

| Item | Value | Source |
|---|---|---|
| Route source | Static analysis (application not booted) | detected |
| Route files read | app/Providers/RouteServiceProvider.php, routes/api.php, routes/web.php | detected |
| Total routes | 9 | _inferred_ (static analysis of route files) |
| Web routes | 6 | _inferred_ (static analysis of route files) |
| API routes | 3 | _inferred_ (static analysis of route files) |
| Named routes | 1 | _inferred_ (static analysis of route files) |
| Closure routes | 2 | _inferred_ (static analysis of route files) |
| Controllers referenced | 4 | _inferred_ (static analysis of route files) |
| Behind authentication middleware | 1 | _inferred_ (static analysis of route files) |
| Signed-URL routes | 0 | _inferred_ (static analysis of route files) |
| Behind custom middleware only | 0 | _inferred_ (middleware not recognised as authentication; may still restrict access) |
| Without authentication middleware | 8 | _inferred_ (static analysis of route files) |

### Routes by HTTP method

| Method | Routes |
|---|---|
| DELETE | 1 |
| GET | 5 |
| POST | 3 |

### Routes requiring review

| Method | URI | Access | Reason | Defined in |
|---|---|---|---|---|
| GET | admin/users | public | path segment "admin" suggests privileged functionality, but no authentication middleware was found | routes/web.php:9 |
| GET | admin/users | public | path segment "admin" suggests privileged functionality, but no authentication middleware was found | routes/web.php:10 |
| POST | api/orders/{order}/refund | public | POST API endpoint without authentication middleware | routes/api.php:6 |
| DELETE | api/users/{id} | public | path segment "users" suggests privileged functionality, but no authentication middleware was found | routes/api.php:7 |
| DELETE | api/users/{id} | public | DELETE API endpoint without authentication middleware | routes/api.php:7 |
| GET | debug/run | public | closure calls Artisan::call() (runs Artisan commands) | routes/web.php:11 |
| GET | debug/run | public | path segment "debug" suggests privileged functionality, but no authentication middleware was found | routes/web.php:11 |
| GET | phpinfo | public | closure calls phpinfo() (exposes PHP configuration and environment variables) | routes/web.php:8 |
| GET | phpinfo | public | path segment "phpinfo" suggests privileged functionality, but no authentication middleware was found | routes/web.php:8 |
| GET | settings | public | path segment "settings" suggests privileged functionality, but no authentication middleware was found | routes/web.php:15 |

### Route list

| Method | URI | Name | Action | Middleware | Access |
|---|---|---|---|---|---|
| GET | admin/users |  | AdminController@index | web | public |
| GET | admin/users | admin.users | AdminController@index | web | public |
| POST | api/login |  | OrderController@refund | api | public |
| POST | api/orders/{order}/refund |  | OrderController@refund | api | public |
| DELETE | api/users/{id} |  | OrderController@destroy | api | public |
| GET | debug/run |  | Closure | web | public |
| GET | phpinfo |  | Closure | web | public |
| POST | reports |  | ReportController@store | web, auth | authenticated |
| GET | settings |  | SettingsController@index | web | public |

> Routes were read from source without booting the application. Routes registered by packages (e.g. Telescope, Horizon, Filament, Sanctum) and routes built dynamically are not included; use --use-artisan to list them.

> Installed packages that register their own routes (not listed above): Debugbar.

## Configuration

| Item | Value | Source |
|---|---|---|
| Environment file | .env | detected |
| Environment | production | detected (.env: APP_ENV) |
| Published config files | 9 | detected (config/) |

### Configuration overview

| Area | Setting | Value | Source | Origin |
|---|---|---|---|---|
| Application | Environment | production | detected | .env: APP_ENV |
| Application | Debug mode | true | detected | .env: APP_DEBUG |
| Application | URL | https://shop.example.test | detected | .env: APP_URL |
| Application | Timezone | UTC | detected | config/app.php:timezone |
| Application | Locale | en | detected | config/app.php:locale |
| Application | Cipher | AES-256-CBC | detected | config/app.php:cipher |
| Application | Maintenance driver | unknown | unknown | config/app.php:maintenance.driver |
| Session | Driver | array | detected | .env: SESSION_DRIVER |
| Session | Lifetime (minutes) | 120 | inferred | config/session.php:lifetime (env('SESSION_LIFETIME') default) |
| Session | Encrypted | false | detected | config/session.php:encrypt |
| Session | Secure cookie | null | inferred | config/session.php:secure (env('SESSION_SECURE_COOKIE') unset, no default) |
| Session | HTTP-only cookie | false | detected | config/session.php:http_only |
| Session | SameSite | none | detected | config/session.php:same_site |
| Session | Expire on close | unknown | unknown | config/session.php:expire_on_close |
| Cache | Default store | file | inferred | config/cache.php:default (env('CACHE_DRIVER') default) |
| Queue | Default connection | sync | detected | .env: QUEUE_CONNECTION |
| Queue | Failed job driver | unknown | unknown | config/queue.php:failed.driver |
| Logging | Default channel | stack | inferred | config/logging.php:default (env('LOG_CHANNEL') default) |
| Filesystem | Default disk | unknown | unknown | config/filesystems.php:default |
| Authentication | Default guard | unknown | unknown | config/auth.php:defaults.guard |
| Authentication | Password confirmation timeout (s) | unknown | unknown | config/auth.php:password_timeout |
| Hashing | Driver | bcrypt | detected | config/hashing.php:driver |
| Hashing | Bcrypt rounds | 4 | detected | .env: BCRYPT_ROUNDS |
| Logging | Level | debug | detected | .env: LOG_LEVEL |
| CORS | Paths | api/*, sanctum/csrf-cookie | detected | config/cors.php:paths |
| CORS | Allowed origins | * | detected | config/cors.php:allowed_origins |
| CORS | Allowed origin patterns | (none) | detected | config/cors.php:allowed_origins_patterns |
| CORS | Allowed methods | * | detected | config/cors.php:allowed_methods |
| CORS | Supports credentials | true | detected | config/cors.php:supports_credentials |
| Application | Application key | Empty | detected | .env: APP_KEY (value never read) |

## Database

| Item | Value | Source |
|---|---|---|
| Default connection | mysql | detected (.env: DB_CONNECTION) |
| Database technology | MySQL | detected (config/database.php) |
| Configured connections | mysql | detected (config/database.php) |
| Migration files | 1 | detected (database/migrations) |
| Tables created by migrations | 1 | detected (Schema::create()) |
| Foreign key constraints | 1 | detected (constrained() / foreign()) |
| Explicit indexes | 2 | detected (index() / unique() / primary() / morphs()) |
| Seeders | 0 | detected (database/seeders) |
| Factories | 0 | detected (database/factories) |

### Connections

| Name | Driver |  |
|---|---|---|
| mysql | mysql | default |

### Tables (from migrations)

| Table | Created in | Key/index columns seen | Foreign keys | Indexes |
|---|---|---|---|---|
| orders | database/migrations/2024_01_01_000000_create_orders_table.php | 6 | 1 | 1 |

> The database was not connected to. Schema details come from migration files only; tables created by packages or by raw SQL may be missing.

## Security

| Item | Value | Source |
|---|---|---|
| Project rules | 6 | detected |
| Source rules | 4 | detected |
| Files scanned | 30 | detected (app, bootstrap, config, database, routes, resources) |
| PHP files parsed | 25 | detected |
| Git tracking | Unknown | detected (git ls-files) |

### Security checks with findings

| Rule | Severity | Confidence | Finding |
|---|---|---|---|
| sec.debug-in-production | HIGH | confirmed | Debug mode is enabled in a production environment |
| sec.app-key-missing | HIGH | confirmed | APP_KEY is not set |
| sec.app-key-in-example | MEDIUM | confirmed | .env.example contains an application key |
| sec.app-key-hardcoded | HIGH | confirmed | The application key is hardcoded in config/app.php |
| sec.session-not-http-only | MEDIUM | confirmed | Session cookie is readable by JavaScript |
| sec.session-same-site-none | MEDIUM | potential | SameSite=None session cookie without the Secure flag |
| sec.session-cookie-not-secure | LOW | potential | Session cookie is not marked Secure in production |
| sec.cors-wildcard-credentials | HIGH | confirmed | CORS allows any origin together with credentials |
| sec.weak-bcrypt-rounds | MEDIUM | confirmed | Bcrypt work factor is 4 in production |
| sec.debug-package-in-production | MEDIUM | confirmed | Laravel Debugbar is a production dependency |
| sec.debug-tool-enabled-in-production | HIGH | confirmed | Laravel Debugbar is enabled in production |
| sec.public-sensitive-file | HIGH | potential | Public directory contains 1 debug or admin script |
| sec.env-variant-present | LOW | potential | 1 environment file variant present in the project root |
| sec.env-permissions | MEDIUM | confirmed | .env is world-readable |
| sec.route-phpinfo | HIGH | confirmed | A public route calls phpinfo() |
| sec.route-dangerous-closure | HIGH | potential | 1 route closure runs dangerous operations without authentication |
| sec.route-sensitive-public | MEDIUM | potential | 4 routes with sensitive-looking paths have no authentication middleware |
| sec.route-api-write-public | LOW | potential | 1 API endpoint changes data without authentication middleware |
| sec.csrf-disabled | HIGH | confirmed | CSRF protection is disabled for all routes |
| sec.trust-all-proxies | LOW | potential | All proxies are trusted |
| sec.secret-token | HIGH | confirmed | 1 value matches a known credential format |
| sec.secret-literal | MEDIUM | confirmed | 3 secrets are hardcoded |
| sec.secret-env-default | MEDIUM | potential | 1 env() default contains a secret |
| sec.secret-url-credentials | MEDIUM | confirmed | 1 URL embeds a password |
| sec.secret-in-env-variant | MEDIUM | potential | 1 secret-looking value in an env file other than .env |
| sec.command-injection | HIGH | potential | Shell command built with request input (1 occurrence) |
| sec.unsafe-unserialize | MEDIUM | potential | Unsafe unserialize() of non-literal data (1 occurrence) |
| sec.sql-injection | HIGH | potential | Raw SQL built with request input (1 occurrence) |
| sec.raw-sql-concatenation | MEDIUM | potential | Raw SQL assembled by concatenation (1 occurrence) |
| sec.path-or-url-from-input | MEDIUM | potential | File or URL access using request input (1 occurrence) |
| sec.open-redirect | LOW | potential | Redirect to a URL from request input (1 occurrence) |
| sec.mass-assignment-request-all | LOW | potential | Model writes with all request input (1 occurrence) |
| sec.weak-password-hash | MEDIUM | potential | Fast hash (md5/sha1) applied to a password (1 occurrence) |
| sec.debug-statement | INFO | potential | Debug statement left in application code (1 occurrence) |
| sec.model-unguard | MEDIUM | potential | Mass-assignment protection is disabled globally |
| sec.model-guarded-empty | LOW | potential | 1 model disables mass-assignment protection |
| sec.blade-unescaped-input | HIGH | potential | Request input is echoed without escaping |
| sec.blade-unescaped-output | LOW | potential | 1 Blade expression prints unescaped HTML |

> Git tracking could not be determined (not a git checkout, git unavailable, or the repository is not trusted by git), so "committed file" checks were skipped.

> tests/ is not scanned. Code findings are pattern-based and local to single expressions; they need manual review and do not prove exploitability.

## Architecture

| Item | Value | Source |
|---|---|---|
| Overview | Laravel 10 application using the classic kernel-based structure with 4 controllers, 2 models and 9 routes. It serves both web pages and an API. User interface: Blade (1 view). Detected structure: Service layer (1 service class). | _inferred_ (derived from detected classes, packages and routes) |
| Code roots scanned | app | detected (composer.json autoload) |
| Classes, interfaces, traits and enums | 12 | detected |
| Patterns detected | Service layer (1 service class) | _inferred_ |
| Frontend | Blade (1 view) | detected |
| Authentication packages | None (framework defaults) | detected (composer.json) |
| API surface | 3 of 9 routes are API routes | _inferred_ (route analysis) |
| Outbound HTTP | 0 Http facade calls, 0 Guzzle clients | detected (static scan) |
| Third-party integrations | None detected | detected (composer.json) |
| Configured services | stripe, mailgun, assistant, cache_url | detected (config/services.php) |

### Component inventory

| Component | Count | Examples | Main location |
|---|---|---|---|
| Controllers | 4 | AdminController, Controller, OrderController, ReportController | app/Http/Controllers |
| Middleware | 2 | TrustProxies, VerifyCsrfToken | app/Http/Middleware |
| Models | 2 | Order, User | app/Models |
| Service providers | 2 | AppServiceProvider, RouteServiceProvider | app/Providers |
| Services | 1 | PaymentService | app/Services |
| Other classes | 1 | Kernel | app/Http |

> This overview is inferred from file structure, class inheritance and installed packages. A missing conventional directory does not mean anything is wrong.

## Code Quality

| Item | Value | Source |
|---|---|---|
| Test framework | PHPUnit | detected (composer.json) |
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
| Laravel Pint | not required | none | no |
| PHP CS Fixer | not required | none | no |
| PHP_CodeSniffer | not required | none | no |
| Rector | not required | none | n/a |

> Tools were detected but not run. Use --run-quality-tools (or --run-quality-tools=phpstan,pint) to run them; you will be asked to confirm.

## Operations

External commands run during this inspection:

| Time | Purpose | Command | Outcome | Network |
|---|---|---|---|---|
| 2026-11-01T13:29:16+00:00 | Read Composer version | `composer --version --no-ansi` | completed (exit 0) | no |
| 2026-11-01T13:29:16+00:00 | Read Node.js version | `node --version` | completed (exit 0) | no |
| 2026-11-01T13:29:16+00:00 | Read npm version | `npm --version` | completed (exit 0) | no |
| 2026-11-01T13:29:16+00:00 | List files tracked by git (to tell committed files from local ones) | `git -c core.fsmonitor=false -c core.untrackedCache=false -C /path/to/insecure-app ls-files -z --cached` | completed (exit 128) | no |

---

_Generated by Laravel Project Inspector 0.1.0 on 2026-11-01T13:29:16+00:00._

_Source labels: **detected** = read directly from project files; **inferred** = derived from constraints, defaults or examples; **unknown** = needs runtime evaluation._
