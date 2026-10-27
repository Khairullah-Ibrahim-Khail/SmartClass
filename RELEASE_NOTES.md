# SmartClass — Release notes

## 2026-10-27 — quality-assurance release

### Security
- The database password is no longer written in the code (`config/db.php`); use `config/local.php` (template: `config/local.example.php`). **Rotate the database password** — the old one is still in the first Git commit.
- `.git`, `.githooks`, `tests/`, `qa/`, `TODO.md`, `README.md` are no longer served by Apache.
- `setup.php` locks itself once an administrator exists (no wipe, no new accounts); a deliberate reinstall needs the file `config/allow_reinstall`.
- Removed the old AJAX sign-in and sign-up in `ajax/handler.php` (they skipped the sign-in lockout, two-step sign-in and registration rules).

### Suggestions (new)
- Teacher dashboard card "Suggestions": six fixed rules over the teacher's own classes (low attendance, missing work, grading backlog, attendance not taken, hard quiz, inactive students), ranked, dismissible for 14 days.
- Proposed messages: for missing work, low attendance and inactive students the teacher reads/edits a ready message and presses **Approve & send**; the student gets a notification, logged. Nothing is sent without the click. Optional AI polish of the teacher's own text (school AI switch and budget apply). Migration 063.

### Hosting
- Everything the pages load (Bootstrap, jQuery, SweetAlert2, Font Awesome, Bootstrap Icons, Google fonts) is now served from `assets/vendor/`: no CDN, works without outside internet, nothing outside can change what runs in a browser.
- `config/local.php` can set `base_path` (domain root = `''`) and `trust_proxy` (HTTPS ended by Cloudflare/a proxy): no code edits needed.
- New: `bin/preflight.php` (production check), `bin/package.sh` (clean release archive), `deploy/` (Apache, nginx, PHP, cron files), Hosting Guide PDF.
- The login page shows only the sign-in form, the Register button and the language links (no demo-credentials box).

### Fixes
- The password-reset page no longer crashes (it used an undefined constant); same for `cron/reset_password.php`.
- The installer now installs the current schema (76 tables) and records all 62 migrations; before, it used an outdated 66-table schema.
- Form fields without an accessible name: quiz options and the "return for changes" note.
- `bin/check.sh` runs the tests once and prints the summary; the test runner no longer dies silently when a suite is loaded twice.

### Added
- Tests: route matrix (2 330 requests), security probe (1 152 requests), upload security, performance, accessibility, QA regressions — 176 tests in total, all passing.
- Documentation: User Manual, Administrator and Operations Manual, Installation Guide (LaTeX + PDF, `docs/`); rewritten `README.md`; reports in `qa/`.

### Open
See `qa/release-readiness.md` (third-party scripts without integrity checks, browsers and load not tested, owner actions).
