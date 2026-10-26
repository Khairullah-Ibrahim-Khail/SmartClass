# SmartClass — TODO (actual state of the code)

Two roles only: **teacher** and **student**. No admin.
Students self-register and wait for a teacher to approve them; teachers create
classes with a unique code; students join with that code.

**Last updated:** 2026-10-20

---

## ✅ Live (built, tested over HTTP)

| Area | Files | Notes |
| --- | --- | --- |
| Auth | `pages/auth/login.php`, `register.php`, `pending.php`, `logout.php` | CSRF on login/register; session id regenerated on login; inactive (rejected) accounts signed out |
| Dashboard | `pages/dashboard.php` | Role-based, all live data: 6 stat cards with trend lines, recent activity, deadlines, approvals/grading (teacher) or grades/resources (student); every item links to its page |
| Classes | `pages/classes/*` + `includes/classes.php`, `includes/class_header.php` | Modular: one page per section — Stream (posts, comments, pinning, student posting setting), Classwork (topics), People (invite link, remove, leave), Grades (gradebook + CSV), Insights (analytics), student progress page, settings (theme, stream permission, reset code, archive/restore) |
| Notifications | `pages/notifications/*` + header bell | Unread badge, dropdown, inbox, mark read; links validated in-app |
| Joining & approval | `pages/classes/join.php`, `people.php`, `pages/auth/register.php`, `includes/classes.php` | Class code / invite link → join request the class teacher approves (per-class setting, on by default); registering with a code sends account + class approval to that teacher in one step; students can cancel; decline notifies; AJAX join follows the same rules |
| Attendance | `pages/classes/attendance.php`, `attendance_export.php`, `pages/attendance/*` | Per class & date: Present / Late / Absent / Official Present + remarks, edit past days, report with rates, CSV, absent students notified, students see only their own record |
| Quizzes | `pages/quizzes/*` | Multiple choice, auto-graded, time limit enforced server-side, review, results table |
| Student approvals | `pages/students/approve.php` | Approve / reject (reject = `is_active = 0`, reversible); student notified on approval |
| Lectures | `pages/lectures/add.php`, `view.php` | Heading, content (paragraphs + ``` code blocks), teacher notes, video link (YouTube embedded) |
| Resources | `pages/resources/upload.php`, `view.php`, `download.php` | Type checked by extension **and** content; size capped; random filename; served only through `download.php` with class-access check; teacher delete; download counter |
| Assignments | `pages/assignments/create.php`, `view.php`, `submit.php`, `download.php` | Due date + max marks; text and/or file submission; resubmit until graded; late flag; teacher grading with feedback |
| Shared | `includes/functions.php` | `csrfField()/verifyCsrf()`, `getAccessibleClass()/getUserClasses()`, `storeUpload()`, `notify*()`, `logActivity()` |
| Installer | `setup.php` | Status check, schema import (RESET guard), first teacher |

## 🔧 Broken legacy pages (written for an older schema — not usable yet)

- [ ] `includes/header.php`, `includes/footer.php` — old layout, now only used by `viewStudents.php`. Remove only after that page is rebuilt (ask first).

## 📋 Next, in order

1. [ ] Quizzes (design: `designs/pages/quize.html`) — needs new tables (quizzes, questions, options, attempts)
2. [x] Attendance — class Attendance page (sheet + report + CSV), `pages/attendance/markAttendance.php` rebuilt, `view.php` overview; feeds Insights and student progress
3. [x] Notifications center (bell + inbox)
4. [x] Profile (details, photo, password) — [ ] backup still open
5. [x] Lecture edit/delete, assignment edit/delete
6. [x] Students roster (`pages/students/viewStudents.php` rebuilt)
7. [ ] Email notifications + multi-teacher — see docs/FEATURE_NOTES.md
8. [ ] AI features — see docs/IMPROVEMENT_PLAN.md

## ⚠️ Known issues

- [ ] **Double-encoded text in older data**: `register.php`, `classes/create.php`, `classes/join.php` store input through `sanitize()` (HTML-encodes) and pages escape again on output, so `O'Brien` shows as `O&#039;Brien`. New modules store raw text and escape on output. Fix = stop sanitizing on input + one-off `html_entity_decode` of existing `users.name`, `classes.class_name/description/tags`.
- [ ] `generateClassCode()` uses `rand()` (predictable); switch to `random_int()`.
- [ ] Logout is a GET link (low-risk logout CSRF).
- [ ] `setup.php` POST forms have no CSRF token (installer runs before sessions/DB exist) — delete `setup.php` after installing.
- [ ] Local only: Apache's systemd unit has `ProtectHome=read-only`, so Apache cannot write `uploads/` or `logs/` under `/home` (see README → Local Apache).

## 🗄️ Database changes

- `config/smartclass_db.sql` — full schema for fresh installs.
- `config/migrations/001_lectures_resources_submissions.sql` — run once on databases installed before 2026-10-20 (adds `lectures.notes`, resource description/category/original_name/download_count, `submissions.original_name`). Applied to the local `smartclass_db`.

## 🔑 Demo credentials

| Role | Email | Password |
| --- | --- | --- |
| Teacher | teacher@test.com | password |
| Student | student@test.com | password |

## 📚 Planning documents

- `docs/CLASS_IMPROVEMENTS.md` — private-comment inbox, "Message teacher", class overview strip, Due this week, calendar, stream/classwork/people/grades upgrades (agreed, not built)
- `docs/AI_PLAN.md` — multi-provider AI (OpenAI, Claude, Groq, Gemini, Mistral, DeepSeek, OpenRouter, Ollama, OpenAI-compatible) and every AI feature for teachers and students (agreed, not built)
- `docs/FEATURE_NOTES.md` — email notifications (built), multi-teacher / co-teachers (not built)
- `docs/TESTING_CHECKLIST.md` — what to check by hand: real browsers, uploads on Apache, real email, cPanel deployment, workflows
- `docs/IMPROVEMENT_PLAN.md` — audit, plan and progress log
