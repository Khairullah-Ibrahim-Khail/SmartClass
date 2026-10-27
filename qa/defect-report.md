# SmartClass — Defect report

Every entry was reproduced or read in the code before it was written down. "Verified" says how the fix was proven.

| ID | Severity | Area | Status |
| --- | --- | --- | --- |
| D-001 | High | Installer | Fixed, verified |
| D-002 | Low | Documentation | Fixed |
| D-003 | High | Password reset | Fixed, regression test |
| D-004 | Low | Code | Fixed |
| D-005 | Medium | Accessibility | Fixed, regression test |
| S-001 | High | Secrets | Code fixed; **Git history still contains the old password** |
| S-002 | Medium | Supply chain | Fixed (self-hosted), regression test |
| S-003 | High | Web exposure of `.git`, `tests/`; unauthenticated installer | Fixed, verified on the local Apache and on a clean copy |
| S-004 | High | Legacy AJAX sign-in / sign-up | Fixed, regression test |
| Q-001 | Low | Test tool | Fixed |
| Q-002 | Low | Check script | Fixed |
| N-001 | Info | Language files | Open — no action proposed |

## D-001 — The installer built a database from an outdated schema
- **Where:** `setup.php` (line 39 and the import call).
- **Reproduce:** read: it imported `config/smartclass_db.sql`, the old base schema (66 tables), and ran no migrations. The current schema has 76 tables and 62 migrations.
- **Root cause:** the installer was never moved to `config/install_schema.sql` when migration tracking was introduced.
- **Fix:** the installer creates the database if needed, runs `install_schema.sql` statement by statement and records all migrations as applied (same method as `bin/create_customer.php` and `tests/run.php`).
- **Verified:** copied the project to an empty folder with a `config/local.php` pointing at a new database, ran the installer over HTTP (154 statements), created the first teacher and signed in: 76 tables, 62 of 62 migrations recorded, sign-in redirected to the dashboard. The throw-away database was dropped. A static regression test (`QaRegressionTest`, S-001/D-001) keeps the installer pointed at `install_schema.sql`.

## D-002 — README described a different, much smaller system
- **Where:** `README.md` (9 tables, demo passwords `teacher@test.com` / `password`, "not working yet" pages).
- **Fix:** rewritten: features, roles, requirements, installation, upgrade, tests, documentation map, security notes. Added `config/local.example.php` (placeholders only).
- **Verified:** the install steps follow what the clean install above did.

## D-003 — Password reset page crashed with HTTP 500
- **Where:** `pages/auth/reset_password.php` (inline script, line ~110) and `cron/reset_password.php`.
- **Reproduce:** open a valid reset link → "Undefined constant PASSWORD_MIN_LENGTH" (also visible in `logs/php-error.log`).
- **Root cause:** code used a constant that does not exist; the minimum length comes from `passwordMinLength()`.
- **Fix:** both files call `passwordMinLength()`.
- **Verified:** two regression tests in `QaRegressionTest`.

## D-004 — A function was called with an extra argument
- **Where:** `includes/classes.php` lines 382, 399 (`notifyClassTeachers` takes 6 parameters, 7 were passed). PHP ignores it, so there was no visible effect.
- **Fix:** extra argument removed. **Verified:** full suite.

## D-005 — Form fields without an accessible name
- **Where:** `pages/quizzes/create.php` (the four option inputs of every question) and `pages/assignments/grade.php` (return-for-changes note).
- **Impact:** a screen reader announces an unnamed text box.
- **Fix:** `aria-label` added. **Verified:** `AccessibilityTest` (79 pages) passes.

## S-001 — A real database password was written in the code
- **Where:** `config/db.php` line 51 (default value of `DB_PASS`) — and in the first Git commit (`2f068be`), which has been pushed.
- **Fix in code:** the default is now an empty string; secrets come only from `config/local.php` (ignored by Git) or the customer files. `config/local.example.php` shows the format. A test fails if the old value returns to `config/db.php`, `setup.php` or `includes/instances.php`.
- **Not fixable by a code change:** removing the value from the working tree does not remove it from history.
- **Recommended action (owner's decision):** change the database password on every server that ever used it (then update `config/local.php`), and decide whether to rewrite the repository history. Treat the old password as public if the repository was ever shared.
- **Scan result:** no API keys, private keys or `.env` files are tracked. `config/local.php` and `config/instances/*` are ignored by Git (checked with `git ls-files`).

## S-002 — Third-party scripts were loaded from CDNs without integrity checks (fixed)
- **Where:** `includes/app_header.php`, `includes/header.php`, `includes/footer.php` and the sign-in pages load Bootstrap, jQuery (two different versions: 3.6.0 and 3.7.1), Font Awesome and SweetAlert2 (`sweetalert2@11`, a floating version) from `cdn.jsdelivr.net`, `cdnjs.cloudflare.com` and `code.jquery.com`; no `integrity=` attribute anywhere; no site-wide Content-Security-Policy (only on file downloads).
- **Impact:** a compromised or changed CDN file would run in every user's session; the school also needs internet access for the interface to look right.
- **Fix:** Bootstrap 5.3.2, jQuery 3.7.1 (one version), SweetAlert2 11.14.5 (pinned), Font Awesome 6.4.0 (with its font files), Bootstrap Icons 1.11.1 and the Google fonts (Poppins, Noto Naskh Arabic, Noto Sans Arabic) now live in `assets/vendor/` and every page references them locally; the legacy IE shims were removed. The site no longer needs any outside server to load. `SelfHostedTest` fails if any page references a CDN or a missing vendor file. Checked in Chrome: English and Pashto (right-to-left) dashboards render with icons and fonts, `Swal` and jQuery load. A site-wide Content-Security-Policy was *not* added: the pages use many inline scripts, so a CSP strict enough to matter would need a larger refactor.

## S-003 — Repository, tests and installer were reachable from the web
- **Where / reproduce:** on the local Apache (read-only requests): `GET /myProject/SmartClass/.git/config` → **HTTP 200**; `tests/lib.php` → 200; `TODO.md` → 200; `setup.php` → 200. (`config/`, `includes/`, `bin/`, `cron/`, `logs/`, `backups/`, `sessions/`, `uploads/`, `instances/` correctly returned 403.)
- **Impact:** with `.git` readable anyone who can reach the server can download the full source and history (including the old password of S-001). Separately, `setup.php` had no sign-in check: after installation anybody could POST `action=install&confirm_reset=RESET` and wipe the database, or POST `action=create_teacher` and create an approved teacher account (read in the code; deliberately not exploited on the live system).
- **Fix:** root `.htaccess` answers 404 for `.git`, `.githooks`, `.github`, `.idea`, `.vscode` and denies `TODO.md`/`README.md`; `tests/.htaccess` and `qa/.htaccess` deny everything; `setup.php` locks itself as soon as the database contains an administrator (no wipe, no new accounts, forms hidden). A deliberate reinstall needs the file `config/allow_reinstall`, which only someone with file access can create. The page text was also corrected (it still promised demo accounts that no longer exist; the minimum password is now 8).
- **Verified:** after the change the same requests on the local Apache return 404 for `.git/config`, `.git/HEAD`, `.githooks/` and 403 for `tests/lib.php`, `TODO.md`, `README.md`, `qa/…`; `setup.php` on the live project now shows "Setup is finished and locked" (GET only). On a clean copy with a throw-away database: install and first-teacher creation still work (154 statements, 1 user); afterwards a wipe attempt and an "intruder" account creation were both refused and the user count stayed 1. Static regression test `QaRegressionTest` (S-003). Delete `setup.php` after installation anyway.

## S-004 — A second, weaker sign-in and sign-up existed in `ajax/handler.php`
- **Where:** `ajax/handler.php`, actions `login` and `register`, open to visitors.
- **Reproduce:** a visitor sends `action=login` with a correct e-mail/password and the header `X-Requested-With: XMLHttpRequest` → `{"success":true,"message":"Login successful"}` and a session. The regression test failed before the fix.
- **Impact:** that code path had none of the protections of the real sign-in page: no lockout after 8 wrong passwords (so unlimited password guessing), no two-step sign-in (an administrator with two-step could be signed in with the password alone), none of the school's registration rules (the sign-up accepted 6-character passwords and skipped the WhatsApp-number and registration settings). Nothing in the application's pages or scripts called these actions.
- **Fix:** both actions and their functions removed; the list of actions open to visitors is now empty.
- **Verified:** `QaRegressionTest` (S-004) fails before and passes after; full suite.

## Q-002 — `bin/check.sh` ran the whole test suite twice
The script ran `php tests/run.php | tail -3` and then ran it again for its result (about 270 s instead of 135 s, and the first run's failure was ignored). Fixed to run once and print the summary line; test `Q-002`.

## Q-001 — The test runner stopped silently when a test file was loaded twice
- **Where:** `tests/run.php` used `require`; a suite that `require_once`s another suite made the runner die with exit 255 and no output.
- **Fix:** `require_once`. **Verified:** full run completes.

## N-001 — Language files contain repeated keys (open, informational)
PHPStan reports 38 array literals in `includes/lang/*.php` that contain the same key twice (for example `'Email'`). PHP keeps the last one, which is the intended "later wins" behaviour of `strings_more.php`. The translation round-trip test passes. No change proposed; each case was not individually compared.

## Static-analysis summary
- PHP syntax: all files pass. JavaScript syntax: all files pass.
- ESLint (undefined variables, duplicate keys, unreachable code): 7 reports, all false positives — `bindLiveClear`/`setFieldError` live in `assets/js/auth.js`, which the page footer loads on every page; `qrcode` is the vendored library.
- PHPStan level 1: 1 654 lines of output; 1 595 are "variable may be undefined" (pages receive variables from the `include`d header; sampled, not reviewed one by one — the route matrix and 164 tests exercise these pages without warnings), 38 duplicate keys (N-001), 17 `??` on variables the analyser thinks are always defined (not reviewed individually), 2 argument-count reports (D-004), 2 missing constants (D-003). No other real defect found.
