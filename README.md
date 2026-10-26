# SmartClass - Google Classroom Clone

## System Overview

A Google Classroom-like system for teachers and students with class codes for joining.

---

## Features

### For Teachers:

- Create classes with unique class codes
- Upload lectures/resources
- Create assignments/quizzes
- View class students
- Manage class content

### For Students:

- Self-registration
- Join classes with class codes
- View lectures/resources
- Submit assignments
- Take quizzes

---

## User Roles

- 👨‍🏫 **Teacher**: Create classes, manage content
- 👨‍🎓 **Student**: Join classes, view content, submit work

---

## Database Schema (`config/smartclass_db.sql`, 9 tables)

- **users** (id, name, email, password, role `teacher|student`, photo, is_approved, is_active, last_login)
- **classes** (id, teacher_id, class_name, class_code, description, tags, image, is_active)
- **class_members** (id, class_id, student_id, joined_at)
- **lectures** (id, class_id, teacher_id, title, content, notes, video_url)
- **resources** (id, class_id, uploaded_by, title, description, category, file_path, original_name, file_type, file_size, download_count)
- **assignments** (id, class_id, teacher_id, title, description, due_date, max_marks)
- **submissions** (id, assignment_id, student_id, content, file_path, original_name, submitted_at, marks_obtained, feedback, graded_by, graded_at, status)
- **notifications**, **activity_log**

Upgrading an older database: run the files in `config/migrations/` that are
newer than your install, in order, once each (001 … 008).

---

## Pages

| Page | Who |
| --- | --- |
| `pages/auth/login.php`, `register.php`, `pending.php`, `logout.php` | everyone |
| `pages/dashboard.php` | teacher + student (role-based) |
| `pages/classes/create.php` | teacher |
| `pages/classes/join.php` | student |
| `pages/classes/my-classes.php` | teacher + student (class cards) |
| `pages/classes/stream.php?id=` — posts, comments, pinned posts, upcoming | teacher + student |
| `pages/classes/classwork.php?id=` — all work grouped by topic | teacher + student |
| `pages/classes/people.php?id=` — teacher, students, invite, remove / leave | teacher + student |
| `pages/classes/grades.php?id=` — gradebook (+ `grades_export.php` CSV) | teacher (all) / student (own) |
| `pages/classes/attendance.php?id=` — take attendance by date, report (+ `attendance_export.php` CSV) | teacher / student (own record) |
| `pages/attendance/markAttendance.php`, `view.php` — attendance across classes | teacher / student |
| `pages/classes/insights.php?id=` — class analytics | teacher |
| `pages/classes/student.php?class_id=&student_id=` — one student's progress | teacher / the student |
| `pages/classes/edit.php?id=` — settings: details, theme, stream, code, archive | teacher |
| `pages/classes/view.php?id=` — old URL, forwards to the pages above | — |
| `pages/students/approve.php` | teacher |
| `pages/lectures/add.php` | teacher |
| `pages/lectures/view.php` (`?class_id=` / `?id=`) | teacher + student |
| `pages/resources/upload.php` | teacher |
| `pages/resources/view.php`, `download.php?id=` | teacher + student |
| `pages/assignments/create.php` | teacher |
| `pages/assignments/view.php` (list / `?id=` with grading) | teacher + student |
| `pages/assignments/submit.php?id=` | student |

Not working yet (old schema): `pages/students/viewStudents.php` — see TODO.md.

---

## File Structure

```
myProject/SmartClass/
├── config/
│   ├── db.php                 (ONLY file to edit per host: DB + BASE_PATH)
│   ├── smartclass_db.sql      (full schema + demo data)
│   └── migrations/            (one-off upgrades for existing databases)
├── includes/
│   ├── auth.php               (include at top of every protected page)
│   ├── functions.php          (CSRF, uploads, notifications, logActivity, asset())
│   ├── classes.php            (Classes module: access, themes, stream rules, topics, gradebook)
│   ├── class_header.php       (shared top of every class page: load + authorise class, banner, tabs)
│   ├── menu.php               (sidebar menu registry)
│   ├── app_header.php / app_footer.php   (shared layout)
│   └── header.php / footer.php           (old layout — legacy pages only)
├── ajax/handler.php           (JSON endpoints: classes, dashboard stats, profile photo)
├── pages/                     (see table above)
├── assets/css, assets/js      (dashboard.css = design; app.css = module styles)
├── designs/                   (HTML/CSS/JS prototypes — the visual spec)
├── uploads/                   (resources/ + submissions/ are web-denied; served by PHP)
├── logs/                      (php-error.log, web-denied)
├── sessions/                  (PHP sessions, web-denied)
└── setup.php                  (installer — delete after install)
```

---

## Getting Started

1. Edit the config block in `config/db.php`.
2. Open `setup.php` → Install Database → create your teacher account.
3. Students register themselves and wait for a teacher to approve them
   (Students → Approve Students).

---

## Demo Credentials (After Setup)

| Role    | Email            | Password |
| ------- | ---------------- | -------- |
| Teacher | teacher@test.com | password |
| Student | student@test.com | password |

---

## Uploads

- Allowed: PDF, DOC/DOCX, PPT/PPTX, XLS/XLSX, TXT, ZIP, JPG/PNG, MP4.
  The type is checked by extension **and** by the file's real content.
- Max size: 10 MB, or less if `upload_max_filesize` / `post_max_size` in
  php.ini is lower (the form shows the effective limit).
- Files are saved under a random name; the user's filename is only shown and
  used as the download name. `uploads/resources/` and `uploads/submissions/`
  get a `Require all denied` `.htaccess`, and downloads go through
  `pages/resources/download.php` / `pages/assignments/download.php`, which
  check class membership.
- On **nginx** hosting `.htaccess` is ignored: add a rule denying
  `/uploads/resources/`, `/uploads/submissions/`, `/logs/`, `/config/` and
  `/sessions/`.

---

## Deploying to a Real Host (cPanel / VPS / shared hosting)

Everything you need to change lives in ONE config block at the top of
`config/db.php`:

1. Upload the `SmartClass` folder to your host (e.g. `public_html/`).
2. Create a MySQL database + user in your hosting panel.
3. Edit the config block in `config/db.php`:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — your hosting DB credentials
   - `BASE_PATH` — where the app lives:
     - `''` if uploaded to the domain root (`https://yourdomain.com`)
     - `'/smartclass'` if in a subfolder (`https://yourdomain.com/smartclass`)
4. Open `https://yourdomain.com/setup.php` → Install Database → create your
   teacher account.
5. Make sure `uploads/`, `logs/` and `sessions/` are writable by PHP (they
   are by default on normal hosting).
6. Delete `setup.php`.

No other file needs editing. Sessions, URLs, and redirects adapt to the
host automatically.

---

---

## Demo data

```
php config/seed_demo.php              # add demo data (10 classes, 25+ students, lectures, work, quizzes, attendance…)
php config/seed_demo.php --resources  # add demo resource files (needs a writable uploads/)
php config/seed_demo.php --remove     # delete all demo data — nothing else is touched
```

Demo accounts end in `@smartclass.demo` (password `password`); demo classes have
codes starting with `DEMO`. teacher@test.com teaches most demo classes and
student@test.com is enrolled in five of them.

---

## Email notifications

Off until a teacher sets them up in **Settings › Email Settings** (SMTP account
or server mail(), sender, site address, delivery). Every in-app notification can
also be emailed; each user picks instant / daily summary / off per category on
their profile; every email has a one-click unsubscribe link. The SMTP password is
stored encrypted. For background delivery add the two cron jobs shown on the
settings page (`cron/send_emails.php`, and `--digest` once a day).

**Forgot password:** with email on, the login page shows "Forgot password?". The account's
email gets a one-time link (valid 60 minutes, only its hash is stored, at most 3 per hour); the
page answers the same for any address. With email off (or if the only super admin is locked
out) set a password from the command line:
`php cron/reset_password.php someone@example.com` (it asks for the new password).

---

## Hosting customers

One code folder can serve many customers, each with their own database and admin; you pause, switch services and support them from
**Customers** (original system only). See `docs/CUSTOMERS.md`.

---

## Local Apache (this machine)

> If uploads fail here: Apache is running with systemd `ProtectHome=read-only`. Run `sh bin/fix_local_apache.sh` once (needs sudo), then check **Settings › System check**.


Apache's systemd unit has `ProtectHome=read-only`, so Apache cannot write
anywhere under `/home` — uploads fail and errors go to
`/var/log/apache2/error.log` instead of `logs/`. Allow the three folders:

```
sudo systemctl edit apache2
#   [Service]
#   ReadWritePaths=/home/khairullah/Documents/VS-Code/myProject/SmartClass/uploads /home/khairullah/Documents/VS-Code/myProject/SmartClass/logs /home/khairullah/Documents/VS-Code/myProject/SmartClass/sessions
sudo systemctl restart apache2
```

---

**Last Updated:** October 2026
**Status:** In active development — see dashboard "System Modules" for live status
