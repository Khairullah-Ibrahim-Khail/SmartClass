# SmartClass — Test plan

Version under test: the working tree on 2026-10-27 (62 migrations applied, 76 tables). Environment: Kali Linux, PHP 8.4.27, MariaDB 11.8.6, 8 CPUs, 16 GB RAM.

## Scope

The whole application: 145 page scripts in `pages/` (33 modules), 65 shared PHP files in `includes/`, 45 JavaScript files, the command-line tools in `bin/` and `cron/`, the installer (`setup.php`), the schema (`config/install_schema.sql` + 62 migrations) and the documentation.

Out of scope (cannot be proven from this machine, listed again in `release-readiness.md`): real WhatsApp (Meta) delivery, real AI providers, real e-mail delivery, a public HTTPS server, many simultaneous users on Apache/PHP-FPM, phones and tablets (only a narrow browser window is emulated).

## Principles

1. Tests never touch real data. `tests/run.php` creates a database `sc_test_<random>` from `config/install_schema.sql` plus all migrations, starts PHP's built-in web server against it and removes both at the end. It stops if the application is not using that database.
2. Real HTTP, real pages, a real database; AI providers and push services are replaced by local fake servers (`tests/mock_ai_server.php`, `tests/mock_push_server.php`).
3. A failing test is fixed in the code or, if the test was wrong, in the test with the reason written down — never deleted or skipped.
4. Every confirmed defect gets a regression test.

## Test levels and what covers them

| Level | How | Where |
| --- | --- | --- |
| Static analysis | PHP syntax of every file (`php -l`), JavaScript syntax (`node --check`), PHPStan level 1 (one-off), ESLint with `no-undef`, `no-dupe-keys`, `no-unreachable`, … (one-off) | `bin/check.sh`; reports in `defect-report.md` |
| Secret scan | patterns for API keys, private keys, the old DB password in tracked files and in Git history | `defect-report.md` (S-001) |
| Unit / logic | grading scales, final marks, attendance rules, passwords, rate limits, translations | `GradingTest`, `FinalMarksTest`, `AttendanceTest`, `PasswordTest`, `RateLimitTest`, `I18nTest`, `RulesTest` |
| Integration / functional | real pages with a signed-in teacher, student, administrator; forms, redirects, files | feature suites (`Assignment…`, `WhatsApp`, `Research…`, `Lists…`, `Attendance…`, `Quiz…`, `Search`, `Customers`, `Onboarding`, `Push`, `Pwa`, `AiWrite…`, …) |
| Route matrix | every page script × 5 kinds of visitor (visitor, outsider, class student, class teacher, administrator) × up to 4 URL variants = 2 330 requests: no HTTP 5xx, no PHP error text, nothing private shown to a visitor, **and no new line in `logs/php-error.log`** | `RouteMatrixTest` |
| Database | schema checks on the throw-away database (`SchemaTest`), clean install through `setup.php`, backup → restore drill with row count + checksum + foreign-key comparison | `SchemaTest`, `bin/restore_drill.php`, manual clean-install procedure (`test-results.md`) |
| Security | authorization matrix, CSRF, XSS in user text, SQL-injection and script payloads in 20 parameters of every page (1 152 requests), forged form posts without a token, response headers, session cookie flags, upload rules, secrets storage, rate limiting, 2FA, privacy | `PermissionsTest`, `SecurityTest`, `SecurityProbeTest`, `HardeningTest`, `SecretsTest`, `PrivacyTest`, `RateLimitTest` |
| Performance | 120 students, 36 assignments, 1 440 submissions, 2 400 attendance rows, 15 key pages, median of 5 requests | `PerformanceTest` |
| Accessibility | `lang`/`dir`, viewport, title, alt text, form labels, names for icon buttons, duplicate ids on 79 pages; Pashto/Dari pages are right-to-left | `AccessibilityTest` |
| Localization | translation lookup, right-to-left output, and a clean export → import round trip of the translation review file | `I18nTest`, `TranslationReviewTest`, `tests/i18n_coverage.py` |
| Installation / recovery | install on an empty database from a clean copy of the project; backup restore drill | procedure in `test-results.md` |

## Acceptance criteria (release gate)

1. `sh bin/check.sh` finishes with all tests passing and no syntax error.
2. Route matrix and security probe: zero findings, zero new PHP warnings in the error log.
3. A clean install through `setup.php` gives 76 tables, every migration recorded, and a working first-administrator sign-in.
4. The restore drill reports identical rows, checksums and foreign keys.
5. Median response of the key pages under 0.5 s on the reference machine; no request over 2 s.
6. No open defect of severity High or Critical. Medium/Low items are listed with a decision.
7. No secret in tracked files; the history risk is stated.

## How to run

```
sh bin/check.sh                       syntax + every test (about 135 s)
php tests/run.php RouteMatrix         one suite (name filter)
php bin/restore_drill.php             backup/restore drill on the live database (read-only)
```
