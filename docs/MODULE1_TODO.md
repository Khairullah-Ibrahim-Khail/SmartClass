# SmartClass — Module 1: Authentication (Status)

Teacher / student only — no admin. See `MODULE1_PLAN.md` for the design.

**Status: complete** (verified over HTTP on 2026-10-20)

## Done

- [x] `users` table with `role` (teacher/student), `is_approved`, `is_active`
- [x] `config/db.php`: PDO connection, session start (own `sessions/` folder,
      strict mode, self-healing bad cookies), error log → `logs/php-error.log`
- [x] Login with inline validation, SweetAlert2 for failures, CSRF token,
      session id regenerated, audit log entry
- [x] Student registration with inline validation, CSRF token; teachers notified
- [x] Pending page for unapproved students (with "Check again")
- [x] Teacher approval page: approve / reject / approve-after-reject
- [x] Rejected (inactive) users are signed out on their next request
- [x] Logout with audit log entry
- [x] Role helpers `isTeacher()` / `isStudent()` (`isAdmin()` always false)

## Open items

- [ ] Logout is a GET link (low-risk logout CSRF) — make it a POST form
- [ ] Older stored names are HTML-encoded by `sanitize()` on input
      (shows `O&#039;Brien`) — see TODO.md → Known issues
- [ ] Password reset / change password (Profile & Settings module)

## Test checklist

- [x] Login: wrong password → SweetAlert error; empty fields → inline errors
- [x] Login without CSRF token → refused
- [x] Register → pending page → teacher approves → dashboard opens
- [x] Reject → student can no longer log in → approve again → can log in
- [x] A student cannot open `students/approve.php`
- [x] A teacher id cannot be "approved"/"rejected" through the form

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Teacher | teacher@test.com | password |
| Student | student@test.com | password |
