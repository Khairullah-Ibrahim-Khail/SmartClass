# SmartClass

A school classroom system for teachers and students (a Google Classroom-style tool): classes with join codes, lectures,
assignments, quizzes, exams, marks, attendance, forums, events, announcements and research resources, with AI helpers,
WhatsApp and e-mail notifications, three interface languages (English, Pashto, Dari — with right-to-left layout) and
support for hosting several schools from one installation.

Plain PHP 8 (no framework), MariaDB/MySQL, Bootstrap 5, SweetAlert2 and a little vanilla JavaScript.

## Roles

- **Student** — registers, waits for approval, joins classes with a code, reads lectures, submits assignments, takes quizzes and exams.
- **Teacher** — creates classes, publishes content, grades, takes attendance, reads reports.
- **Super admin** — a teacher with the administrator flag: approves users, owns school settings, privacy, AI and WhatsApp rules, backups.

## Requirements

- PHP 8.0 or newer with `pdo_mysql`, `mbstring`, `curl`, `gd` (or `imagick`) and `zip`
- MariaDB 10.5+ / MySQL 8+
- Apache with `AllowOverride All` so the `.htaccess` files apply (they deny web access to private folders and raise the upload limit), or PHP-FPM/nginx with equivalent rules; PHP's built-in server is for development only

## Install (new server)

1. Copy the project into the web root (for example `/var/www/html/SmartClass`).
2. Create a database user, then copy `config/local.example.php` to `config/local.php` and fill in the database login and a random
   `secret_key` (the file is never committed; without it the application uses an empty database password and cannot connect).
3. Set `BASE_PATH` in `config/db.php` to the URL path of the application (`''` when it lives at the domain root).
4. Make `uploads/`, `sessions/`, `backups/` and `logs/` writable by the web server user.
5. Open `setup.php` in the browser: **Install Database** (creates the database from `config/install_schema.sql` and records every
   migration as applied), then create the first teacher account — it becomes the super admin.
6. **Delete `setup.php`** from the server.
7. Schedule the three cron jobs (as the web-server user):
   ```
   */5 * * * *  php /path/to/SmartClass/cron/send_emails.php
   0 18 * * *   php /path/to/SmartClass/cron/send_emails.php --digest
   30 2 * * *   php /path/to/SmartClass/cron/housekeeping.php
   ```

The full procedure, with troubleshooting, is in the Installation Guide (`docs/installation/`).

## Hosting (real server)

Step-by-step for cPanel, Ubuntu + Apache and Ubuntu + nginx: `docs/installation/installation-guide.pdf` (the **Hosting Guide**). Ready-made files are in `deploy/` (Apache site, nginx site, PHP settings, cron lines). Build the server package with `sh bin/package.sh`; after installing run `php bin/preflight.php` (PASS/WARN/FAIL production check).

## Upgrade

Take a backup, update the files, then `php bin/migrate.php` (this installation) or `php bin/update_all.php` (every hosted school; backs up first).

## Tests

```
sh bin/check.sh              PHP + JavaScript syntax, then every test (about 100 s)
php tests/run.php            all tests on a throw-away database (never touches real data)
php tests/run.php Name       only the suites whose file name matches
```

The tests build a database called `sc_test_<random>` from `config/install_schema.sql` plus every migration, start PHP's built-in server
and drive the real pages.

## Documentation

| Where | What |
| --- | --- |
| `docs/ARCHITECTURE.md` | how the code is organised, how to add a page, a migration or a test |
| `docs/CUSTOMERS.md` | hosting several schools |
| `docs/WHATSAPP.md` | WhatsApp buttons and the message queue |
| `docs/PRIVACY.md`, `docs/AI_POLICY_TEMPLATE.md` | privacy and AI rules |
| `docs/CAPACITY.md` | measured capacity |
| `qa/` | test plan, results, defect and security reports, release readiness |

## Security notes

- Secrets live only in `config/local.php` and `config/instances/*` (both ignored by Git). Never put a password in the code.
- Uploaded files are stored under random names in folders the web server refuses to serve; downloads go through PHP and check class membership.
- Every form carries a CSRF token; passwords are hashed; sign-in attempts are rate-limited; optional two-factor sign-in.
