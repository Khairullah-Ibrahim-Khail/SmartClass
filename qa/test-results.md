# SmartClass — Test results

Run on 2026-10-27. Machine: Kali Linux (kernel 6.19), 8 CPUs, 16 GB RAM, PHP 8.4.27, MariaDB 11.8.6, Apache 2.4.66 (local), Google Chrome (headed, under Xvfb) for screenshots. Automated tests run against PHP's built-in web server and a throw-away database; web-exposure checks ran against the local Apache.

## 1. Automated suite — `sh bin/check.sh`

| Item | Result |
| --- | --- |
| PHP syntax (every file) | pass |
| JavaScript syntax (every file) | pass |
| Test files | 45 |
| Tests | **169 run, 169 passed, 0 failed, 0 skipped** (final run: 141.1 s) |

Per suite (tests): Accessibility 1 · AiControl 3 · AiLanguage 1 · AiStream 3 · AiStudentWriting 4 · AiWriteEverywhere 6 · AssignmentWorkflow 6 · AttendanceHub 2 · AttendanceModule 5 · Attendance 5 · CodeRun 8 · Customers 1 · FinalMarks 2 · Grading 10 · Hardening 4 · Health 5 · I18n 5 · ListsEdit 4 · OffsiteBackup 2 · Onboarding 1 · Password 3 · Performance 1 · Permissions 8 · Privacy 11 · Push 3 · Pwa 2 · QaRegression 7 · QuestionBank 1 · QuizReopen 1 · RateLimit 4 · ResearchExtras 5 · Research 3 · RouteMatrix 1 · Rules 1 · Schema 2 · Search 2 · Secrets 2 · SecurityProbe 3 · Security 8 · StudentMarksPage 1 · SupportApproval 2 · TranslationReview 1 · Tutor 4 · UploadSecurity 1 · WhatsApp 14.

By category (counted from the suites above): feature/functional 97 · security 51 (Security 8, SecurityProbe 3, Permissions 8, Password 3, RateLimit 4, Hardening 4, Secrets 2, Privacy 11, UploadSecurity 1, QaRegression 7) · localization 7 (I18n, Rules, TranslationReview) · database/backup/health 10 (Schema, OffsiteBackup, Customers, Health) · route matrix 1 · performance 1 · accessibility 1. Total 169 (incl. SelfHosted 1, QaRegression 8).

**Not run in this environment (and said so by the tests):** the "normal file is stored under a random name" half of `UploadSecurityTest` — the test user cannot write to `uploads/submissions` (owned by the web-server user). The refusal half (10 attack files + a 16 MB file) ran.

## 2. New tests written in this pass

| Test | What it proves |
| --- | --- |
| `RouteMatrixTest` | 2 330 requests (every page script × visitor, outsider, class student, class teacher, administrator × up to 4 URL variants): no HTTP 5xx, no PHP error text, nothing private for a visitor, **no new Warning/Notice/Fatal/SQLSTATE line in `logs/php-error.log`** |
| `SecurityProbeTest` | 1 152 requests with SQL-injection and script payloads in 20 parameters of every page; token-less and wrong-token POSTs to every page change nothing; response headers and cookie flags |
| `UploadSecurityTest` | PHP, disguised PHP, HTML, EXE, empty, extension-less and oversized uploads are refused and leave nothing behind |
| `PerformanceTest` | see section 4 |
| `AccessibilityTest` | 79 pages: lang, viewport, title, alt, labels, names, duplicate ids; Pashto/Dari right-to-left |
| `QaRegressionTest` (7) | D-003 (×2), S-001/D-001, S-003, S-004, Q-002 |

## 3. Other checks (manual commands, results)

| Check | Result |
| --- | --- |
| Clean install: project copied to an empty folder, new empty database, `setup.php` over HTTP | 154 statements; 76 tables; 62/62 migrations recorded; first teacher created; sign-in redirected to the dashboard. Repeated after the S-003 fix: install and first account still work; then a wipe attempt and an extra-account attempt were refused (user count stayed 1). Throw-away databases dropped. |
| Restore drill (`php bin/restore_drill.php`) | 5 392 rows, 118.6 KB backup; restored into a throw-away database; 76 tables and 139 foreign keys identical; PASSED in 1.9 s |
| Web exposure on the local Apache (`curl`) | after the fix: `.git/config`, `.git/HEAD`, `.githooks/` → 404; `tests/lib.php`, `TODO.md`, `README.md`, `qa/…` → 403; `config/`, `includes/`, `bin/`, `cron/`, `logs/`, `backups/`, `sessions/`, `uploads/`, `instances/`, `docs/` and directory listings → 403. Before the fix `.git/config` was **200**. |
| Response headers (Apache) | `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`; the `Server` header shows the Apache version; no HSTS/CSP (the test host is plain HTTP) |
| PHPStan level 1 / ESLint | see `defect-report.md` (no further real defect) |
| Secret scan | no keys/private keys/.env tracked; the old DB password exists only in the first commit (S-001) |
| Documentation build | `sh docs/build.sh` from a clean state: user manual 24 pages, administrator manual 11 pages, installation guide 8 pages; 0 undefined references; all figures present |

## 4. Performance (reference machine, PHP built-in server, 1 worker)

Data: 120 students in 3 classes, 36 assignments, 1 440 submissions, 3 quizzes with 120 attempts, 2 400 attendance rows. Median of 5 requests after one warm-up request, response size in KB. Gate: median < 0.5 s, no request > 2 s (the planning figure in `docs/CAPACITY.md` is 0.2 s per page).

| Role | Page | Median | Max |
| --- | --- | --- | --- |
| student | dashboard | 12 ms | 13 ms |
| student | class stream / classwork / grades | 9 / 12 / 8 ms | ≤ 13 ms |
| teacher | dashboard | 26 ms | 30 ms |
| teacher | people (103 KB) / grades (357 KB) | 15 / 15 ms | ≤ 19 ms |
| teacher | attendance sheet (178 KB) / insights / reports | 12 / 18 / 13 ms | ≤ 20 ms |
| administrator | dashboard / system check / teachers | 9 / 9 / 3 ms | ≤ 10 ms |

All within the gate with a large margin. **Not measured:** concurrent users, PHP-FPM/Apache under load, AI streaming load, a slow network, a cold cache, a database larger than the one above. No capacity guarantee is made.

## 5. Compatibility and usability

- Browser: Chrome only (screenshots, 1366×900, light and dark). Firefox, Safari, Edge, phones and tablets were **not** tested in this pass; a narrow-window scan tool exists (`tests/browser_scan.py`) but was not run.
- Languages: Pashto/Dari output is checked for `lang`/`dir="rtl"`; visual review of every screen in both languages was **not** repeated in this pass.
- Accessibility: automated checks only (see above); no screen-reader or keyboard-only session was run.
