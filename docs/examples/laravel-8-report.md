# Laravel Project Inspection: fixture/laravel-8-app

> The inspected project was not modified. Files were read without executing project code. External commands are listed under Operations.

| | |
|---|---|
| **Project** | fixture/laravel-8-app |
| **Path** | /path/to/laravel-8 |
| **Framework** | Laravel 8.83.27 |
| **Generated** | 2026-11-01 12:48:39 +00:00 |
| **Inspector version** | 0.1.0 |
| **Duration** | 4.9 s |
| **Network checks** | Enabled |

## Summary

8 findings, including 1 critical: immediate attention recommended.

| Severity | Count |
|---|---:|
| CRITICAL | 1 |
| HIGH | 2 |
| MEDIUM | 1 |
| LOW | 2 |
| INFO | 2 |

## Findings

| # | Severity | Confidence | Finding | Component |
|---:|---|---|---|---|
| 1 | **CRITICAL** | confirmed | [laravel/framework v8.83.27 has 6 known security advisories](#finding-1) | `composer.lock: laravel/framework` |
| 2 | **HIGH** | confirmed | [guzzlehttp/guzzle 7.5.0 has 9 known security advisories](#finding-2) | `composer.lock: guzzlehttp/guzzle` |
| 3 | **HIGH** | confirmed | [phpunit/phpunit 9.5.28 has 1 known security advisory](#finding-3) | `composer.lock: phpunit/phpunit` |
| 4 | **MEDIUM** | confirmed | [Laravel 8 no longer receives security fixes](#finding-4) | `composer.lock` |
| 5 | **LOW** | confirmed | [fruitcake/laravel-cors is abandoned](#finding-5) | `composer.lock: fruitcake/laravel-cors` |
| 6 | **LOW** | confirmed | [swiftmailer/swiftmailer is abandoned](#finding-6) | `composer.lock: swiftmailer/swiftmailer` |
| 7 | **INFO** | confirmed | [facade/ignition is abandoned](#finding-7) | `composer.lock: facade/ignition` |
| 8 | **INFO** | confirmed | [5 direct dependencies are behind a major release](#finding-8) | `composer.json` |

### <a id="finding-1"></a>1. [CRITICAL] laravel/framework v8.83.27 has 6 known security advisories

- **Severity:** CRITICAL (confirmed)
- **Component:** `composer.lock: laravel/framework`
- **Analyzer:** dependencies · **Rule:** `deps.security-advisory`

The locked version falls within the affected range of published advisories. Whether the application is exploitable depends on how the package is used.

**Evidence**

```text
- CVE-2026-114509 (critical): Laravel TokenGuard authentication bypass through non-string API credentials; affected <12.68.0|>=13.0.0,<13.29.0 (https://github.com/advisories/GHSA-f9x3-7vq9-cph6)
- CVE-2026-102279 (low): Laravel: XSS in Debug Page Information; affected >=13.0.0,<13.30.0|<12.69.0 (https://github.com/advisories/GHSA-jh5r-qr3c-85q8)
- PKSA-m5cs-t1y6-qpcs (medium): Laravel Framework: Temporary Signed URL Path Confusion; affected <12.61.1|>=13.0.0,<13.12.0 (https://github.com/advisories/GHSA-crmm-hgp2-wgrp)
- PKSA-3r5d-mb8f-1qw9 (high): Laravel Framework: CRLF injection in default email rule ; affected <12.60.0|>=13.0.0,<=13.9.0 (https://github.com/advisories/GHSA-5vg9-5847-vvmq)
- CVE-2025-27515 (medium): Laravel has a File Validation Bypass; affected <10.48.29|>=11.0.0,<11.44.1|>=12.0.0,<12.1.1 (https://github.com/advisories/GHSA-78fx-h6xr-vch4)
- CVE-2024-52301 (high): Laravel environment manipulation via query string; affected <6.20.45|>=7.0.0,<7.30.7|>=8.0.0,<8.83.28|>=9.0.0,<9.52.17|>=10.0.0,<10.48.23|>=11.0.0,<11.31.0 (https://github.com/advisories/GHSA-gv7v-rgg6-548h)
```

**Remediation:** Update laravel/framework to a version outside the affected ranges (&lt;12.68.0|&gt;=13.0.0,&lt;13.29.0; &gt;=13.0.0,&lt;13.30.0|&lt;12.69.0; &lt;12.61.1|&gt;=13.0.0,&lt;13.12.0; &lt;12.60.0|&gt;=13.0.0,&lt;=13.9.0; &lt;10.48.29|&gt;=11.0.0,&lt;11.44.1|&gt;=12.0.0,&lt;12.1.1; &lt;6.20.45|&gt;=7.0.0,&lt;7.30.7|&gt;=8.0.0,&lt;8.83.28|&gt;=9.0.0,&lt;9.52.17|&gt;=10.0.0,&lt;10.48.23|&gt;=11.0.0,&lt;11.31.0), e.g. "composer update laravel/framework" in the project, then run the test suite.

### <a id="finding-2"></a>2. [HIGH] guzzlehttp/guzzle 7.5.0 has 9 known security advisories

- **Severity:** HIGH (confirmed)
- **Component:** `composer.lock: guzzlehttp/guzzle`
- **Analyzer:** dependencies · **Rule:** `deps.security-advisory`

The locked version falls within the affected range of published advisories. Whether the application is exploitable depends on how the package is used.

**Evidence**

```text
- CVE-2026-69246 (high): Guzzle: Noncanonical host can bypass host-based checks; affected >=8.0.0,<8.0.1|<7.15.2 (https://github.com/advisories/GHSA-v5mv-p594-2x33)
- CVE-2026-69245 (medium): Guzzle: Noncanonical cookie domain keeps subdomain scope; affected >=8.0.0,<8.0.1|<7.15.2 (https://github.com/advisories/GHSA-f7vp-7xgx-4w4r)
- CVE-2026-67354 (medium): Guzzle: URI fragments disclosed in redirect Referer headers; affected <7.15.1 (https://github.com/advisories/GHSA-h95v-h523-3mw8)
- CVE-2026-67355 (medium): Guzzle: Host-only cookie scope is not preserved; affected <7.15.1 (https://github.com/advisories/GHSA-wm3w-8rrp-j577)
- CVE-2026-67353 (medium): Guzzle: Unbounded response cookies risk denial of service; affected <7.15.1 (https://github.com/advisories/GHSA-f283-ghqc-fg79)
- CVE-2026-59883 (medium): Guzzle: Cookie Disclosure and Injection via IP-Address Domains; affected <7.12.3 (https://github.com/advisories/GHSA-g446-98w2-8p5w)
- CVE-2026-67339 (medium): Guzzle: Proxy-Authorization headers can be sent to origin servers; affected <7.14.2 (https://github.com/advisories/GHSA-94pj-82f3-465w)
- CVE-2026-55767 (medium): Dot-only cookie domains match all hosts; affected <7.12.1 (https://github.com/guzzle/guzzle/security/advisories/GHSA-cwxw-98qj-8qjx)
- CVE-2026-55568 (medium): Silent HTTPS proxy downgrade to cleartext; affected <7.12.1 (https://github.com/guzzle/guzzle/security/advisories/GHSA-wpwq-4j6v-78m3)
```

**Remediation:** Update guzzlehttp/guzzle to a version outside the affected ranges (&gt;=8.0.0,&lt;8.0.1|&lt;7.15.2; &lt;7.15.1; &lt;7.12.3; &lt;7.14.2; &lt;7.12.1), e.g. "composer update guzzlehttp/guzzle" in the project, then run the test suite.

### <a id="finding-3"></a>3. [HIGH] phpunit/phpunit 9.5.28 has 1 known security advisory

- **Severity:** HIGH (confirmed)
- **Component:** `composer.lock: phpunit/phpunit`
- **Analyzer:** dependencies · **Rule:** `deps.security-advisory`

The locked version falls within the affected range of published advisories. This is a development dependency, which normally is not deployed to production. Whether the application is exploitable depends on how the package is used.

**Evidence**

```text
- CVE-2026-24765 (high): Unsafe Deserialization in PHPT Code Coverage Handling; affected >=0,<8.5.52|>=9.0.0,<9.6.33|>=10.0.0,<10.5.62|>=11.0.0,<11.5.50|>=12.0.0,<12.5.8 (https://github.com/sebastianbergmann/phpunit/security/advisories/GHSA-vvj3-c3rp-c85p)
```

**Remediation:** Update phpunit/phpunit to a version outside the affected ranges (&gt;=0,&lt;8.5.52|&gt;=9.0.0,&lt;9.6.33|&gt;=10.0.0,&lt;10.5.62|&gt;=11.0.0,&lt;11.5.50|&gt;=12.0.0,&lt;12.5.8), e.g. "composer update phpunit/phpunit" in the project, then run the test suite.

### <a id="finding-4"></a>4. [MEDIUM] Laravel 8 no longer receives security fixes

- **Severity:** MEDIUM (confirmed)
- **Component:** `composer.lock`
- **Analyzer:** environment · **Rule:** `env.laravel-end-of-life`

Vulnerabilities discovered in this Laravel version will not be patched upstream. This is a maintenance risk, not proof of a current vulnerability.

**Evidence**

```text
Laravel 8 security support ended on 2023-01-24 (https://laravel.com/docs/releases#support-policy).
```

**Remediation:** Plan an upgrade to a supported Laravel release. Upgrade one major version at a time, following the official upgrade guides.

### <a id="finding-5"></a>5. [LOW] fruitcake/laravel-cors is abandoned

- **Severity:** LOW (confirmed)
- **Component:** `composer.lock: fruitcake/laravel-cors`
- **Analyzer:** dependencies · **Rule:** `deps.abandoned`

The maintainers have marked this package as abandoned. It will not receive bug or security fixes.

**Evidence**

```text
fruitcake/laravel-cors v2.2.0 is marked abandoned.
```

**Remediation:** Find a maintained alternative to fruitcake/laravel-cors, or plan to remove it.

### <a id="finding-6"></a>6. [LOW] swiftmailer/swiftmailer is abandoned

- **Severity:** LOW (confirmed)
- **Component:** `composer.lock: swiftmailer/swiftmailer`
- **Analyzer:** dependencies · **Rule:** `deps.abandoned`

The maintainers have marked this package as abandoned. It will not receive bug or security fixes.

**Evidence**

```text
swiftmailer/swiftmailer v6.3.0 is marked abandoned; suggested replacement: symfony/mailer.
```

**Remediation:** Migrate from swiftmailer/swiftmailer to symfony/mailer.

### <a id="finding-7"></a>7. [INFO] facade/ignition is abandoned

- **Severity:** INFO (confirmed)
- **Component:** `composer.lock: facade/ignition`
- **Analyzer:** dependencies · **Rule:** `deps.abandoned`

The maintainers have marked this development package as abandoned. It will not receive bug or security fixes.

**Evidence**

```text
facade/ignition 2.17.7 is marked abandoned; suggested replacement: spatie/laravel-ignition.
```

**Remediation:** Migrate from facade/ignition to spatie/laravel-ignition.

### <a id="finding-8"></a>8. [INFO] 5 direct dependencies are behind a major release

- **Severity:** INFO (confirmed)
- **Component:** `composer.json`
- **Analyzer:** dependencies · **Rule:** `deps.outdated-major`

Newer major versions exist. Staying far behind makes future upgrades and security patches harder. Major updates may include breaking changes.

**Evidence**

```text
- fruitcake/laravel-cors v2.2.0 -> v3.0.0
- guzzlehttp/guzzle 7.5.0 -> 8.3.1
- laravel/framework v8.83.27 -> v13.39.0
- laravel/sanctum v2.15.1 -> v4.3.3
- phpunit/phpunit 9.5.28 -> 12.5.42
```

**Remediation:** Review the changelogs and upgrade guides, then update one package at a time.

## Environment

| Item | Value | Source |
|---|---|---|
| Laravel version | 8.83.27 | detected (composer.lock) |
| Project layout | Classic (HTTP/Console kernels) | _inferred_ (bootstrap/app.php, app/Http/Kernel.php) |
| Laravel support status | End of life (security fixes ended 2023-01-24) | detected (https://laravel.com/docs/releases#support-policy) |
| PHP requirement | ^7.3\|^8.0 | detected (composer.json) |
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
| Frontend build tool | Laravel Mix | detected (webpack.mix.js) |
| Frontend package manager | npm | detected (package-lock.json) |
| Frontend technologies | Vue | detected (package.json, composer.json) |

### Infrastructure drivers

| Setting | Value | Source | Origin |
|---|---|---|---|
| Database connection | mysql | detected | .env: DB_CONNECTION |
| Cache store | redis | detected | .env: CACHE_DRIVER |
| Queue connection | redis | detected | .env: QUEUE_CONNECTION |
| Session driver | redis | detected | .env: SESSION_DRIVER |
| Log channel | stack | inferred | config/logging.php:default (env('LOG_CHANNEL') default) |
| Filesystem disk | local | inferred | config/filesystems.php:default (env('FILESYSTEM_DRIVER') default) |
| Mailer | smtp | detected | .env: MAIL_MAILER |
| Broadcast connection | null | inferred | config/broadcasting.php:default (env('BROADCAST_DRIVER') default) |

## Dependencies

| Item | Value | Source |
|---|---|---|
| Direct dependencies | 4 | detected (composer.json require) |
| Direct development dependencies | 2 | detected (composer.json require-dev) |
| composer.lock | Present | detected (composer.lock) |
| Locked packages | 7 (5 production, 2 development) | detected (composer.lock) |
| Installed in vendor/ | Not installed | detected (vendor/composer/installed.json) |
| Minimum stability | dev | detected (composer.json) |

### Platform requirements

| Requirement | Constraint |
|---|---|
| php | ^7.3\|^8.0 |

### Direct dependencies

| Package | Constraint | Locked version | Scope |
|---|---|---|---|
| fruitcake/laravel-cors | ^2.0 | v2.2.0 | production |
| guzzlehttp/guzzle | ^7.0.1 | 7.5.0 | production |
| laravel/framework | ^8.75 | v8.83.27 | production |
| laravel/sanctum | ^2.11 | v2.15.1 | production |
| facade/ignition | ^2.5 | 2.17.7 | development |
| phpunit/phpunit | ^9.5.10 | 9.5.28 | development |

### Security advisories

| Package | Advisory | Severity | Affected versions | Title |
|---|---|---|---|---|
| guzzlehttp/guzzle | CVE-2026-69246 | HIGH | &gt;=8.0.0,&lt;8.0.1\|&lt;7.15.2 | Guzzle: Noncanonical host can bypass host-based checks |
| guzzlehttp/guzzle | CVE-2026-69245 | MEDIUM | &gt;=8.0.0,&lt;8.0.1\|&lt;7.15.2 | Guzzle: Noncanonical cookie domain keeps subdomain scope |
| guzzlehttp/guzzle | CVE-2026-67354 | MEDIUM | &lt;7.15.1 | Guzzle: URI fragments disclosed in redirect Referer headers |
| guzzlehttp/guzzle | CVE-2026-67355 | MEDIUM | &lt;7.15.1 | Guzzle: Host-only cookie scope is not preserved |
| guzzlehttp/guzzle | CVE-2026-67353 | MEDIUM | &lt;7.15.1 | Guzzle: Unbounded response cookies risk denial of service |
| guzzlehttp/guzzle | CVE-2026-59883 | MEDIUM | &lt;7.12.3 | Guzzle: Cookie Disclosure and Injection via IP-Address Domains |
| guzzlehttp/guzzle | CVE-2026-67339 | MEDIUM | &lt;7.14.2 | Guzzle: Proxy-Authorization headers can be sent to origin servers |
| guzzlehttp/guzzle | CVE-2026-55767 | MEDIUM | &lt;7.12.1 | Dot-only cookie domains match all hosts |
| guzzlehttp/guzzle | CVE-2026-55568 | MEDIUM | &lt;7.12.1 | Silent HTTPS proxy downgrade to cleartext |
| laravel/framework | CVE-2026-114509 | CRITICAL | &lt;12.68.0\|&gt;=13.0.0,&lt;13.29.0 | Laravel TokenGuard authentication bypass through non-string API credentials |
| laravel/framework | CVE-2026-102279 | LOW | &gt;=13.0.0,&lt;13.30.0\|&lt;12.69.0 | Laravel: XSS in Debug Page Information |
| laravel/framework | PKSA-m5cs-t1y6-qpcs | MEDIUM | &lt;12.61.1\|&gt;=13.0.0,&lt;13.12.0 | Laravel Framework: Temporary Signed URL Path Confusion |
| laravel/framework | PKSA-3r5d-mb8f-1qw9 | HIGH | &lt;12.60.0\|&gt;=13.0.0,&lt;=13.9.0 | Laravel Framework: CRLF injection in default email rule  |
| laravel/framework | CVE-2025-27515 | MEDIUM | &lt;10.48.29\|&gt;=11.0.0,&lt;11.44.1\|&gt;=12.0.0,&lt;12.1.1 | Laravel has a File Validation Bypass |
| laravel/framework | CVE-2024-52301 | HIGH | &lt;6.20.45\|&gt;=7.0.0,&lt;7.30.7\|&gt;=8.0.0,&lt;8.83.28\|&gt;=9.0.0,&lt;9.52.17\|&gt;=10.0.0,&lt;10.48.23\|&gt;=11.0.0,&lt;11.31.0 | Laravel environment manipulation via query string |
| phpunit/phpunit | CVE-2026-24765 | HIGH | &gt;=0,&lt;8.5.52\|&gt;=9.0.0,&lt;9.6.33\|&gt;=10.0.0,&lt;10.5.62\|&gt;=11.0.0,&lt;11.5.50\|&gt;=12.0.0,&lt;12.5.8 | Unsafe Deserialization in PHPT Code Coverage Handling |

### Outdated direct dependencies

| Package | Locked | Latest | Update type |
|---|---|---|---|
| fruitcake/laravel-cors | v2.2.0 | v3.0.0 | major |
| guzzlehttp/guzzle | 7.5.0 | 8.3.1 | major |
| laravel/framework | v8.83.27 | v13.39.0 | major |
| laravel/sanctum | v2.15.1 | v4.3.3 | major |
| phpunit/phpunit | 9.5.28 | 12.5.42 | major |

## Operations

External commands run during this inspection:

| Time | Purpose | Command | Outcome | Network |
|---|---|---|---|---|
| 2026-11-01T12:48:39+00:00 | Read Composer version | `composer --version --no-ansi` | completed (exit 0) | no |
| 2026-11-01T12:48:39+00:00 | Read Node.js version | `node --version` | completed (exit 0) | no |
| 2026-11-01T12:48:39+00:00 | Read npm version | `npm --version` | completed (exit 0) | no |
| 2026-11-01T12:48:41+00:00 | Check composer.lock against the Packagist security advisory database | `composer audit --format=json --abandoned=report --locked --no-plugins --no-scripts --no-interaction --no-ansi --working-dir=/path/to/laravel-8` | completed (exit 1) | yes |
| 2026-11-01T12:48:43+00:00 | List direct dependencies with newer releases on Packagist | `composer outdated --direct --format=json --locked --no-plugins --no-scripts --no-interaction --no-ansi --working-dir=/path/to/laravel-8` | completed (exit 0) | yes |

---

_Generated by Laravel Project Inspector 0.1.0 on 2026-11-01T12:48:39+00:00._

_Source labels: **detected** = read directly from project files; **inferred** = derived from constraints, defaults or examples; **unknown** = needs runtime evaluation._
