# SmartClass — Module 1: Authentication (Plan)

## Scope

Login, student self-registration, teacher approval gate, logout, session
handling and role checks for a **teacher / student** system. There is **no
admin role**.

## Roles

- **Teacher** — creates classes (unique class code), adds lectures, resources
  and assignments, grades work, approves newly registered students.
- **Student** — registers, waits for approval, joins classes with a code,
  reads lectures, downloads resources, submits assignments.

Teachers are created by `setup.php` (first teacher) or directly in the
database; there is no public teacher registration.

## Database (`config/smartclass_db.sql`)

`users` (id, name, email, password, role `teacher|student`, photo,
`is_approved` 0 = pending, `is_active` 0 = rejected/disabled, last_login, ...)

Related tables: `classes`, `class_members`, `lectures`, `resources`,
`assignments`, `submissions`, `notifications`, `activity_log` (9 tables).

## Flow

1. Student registers (`pages/auth/register.php`) → `is_approved = 0`, every
   teacher is notified.
2. Student logs in → `includes/auth.php` sends them to
   `pages/auth/pending.php` until approved.
3. A teacher approves (`pages/students/approve.php`) → student is notified and
   can use the system. Reject sets `is_active = 0` (cannot log in; a teacher can
   still approve later).
4. Logout destroys the session (`pages/auth/logout.php`).

## Components

| File | Role |
| --- | --- |
| `config/db.php` | Only file to edit per host (DB + `BASE_PATH`); `Database`, `Session`, `Auth`, `sanitize()`, `redirect()`; error logging to `logs/php-error.log` |
| `includes/auth.php` | Include at the top of every protected page: login check, active check, approval check, `isTeacher()/isStudent()` |
| `includes/functions.php` | CSRF, class access, uploads, notifications, activity log |
| `pages/auth/*.php` | Login / register / pending / logout |

## Security requirements

- PDO prepared statements only.
- Passwords with `password_hash()` / `password_verify()`.
- `session_regenerate_id(true)` on login; HttpOnly + SameSite=Lax cookie;
  strict session mode.
- CSRF token on every POST form (`csrfField()` / `verifyCsrf()`).
- Inline field errors under inputs; SweetAlert2 only for task-level results.

## Demo accounts (from `smartclass_db.sql`)

| Role | Email | Password |
| --- | --- | --- |
| Teacher | teacher@test.com | password |
| Student | student@test.com | password |
