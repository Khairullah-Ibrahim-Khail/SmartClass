# SmartClass — Feature Notes (agreed to build)

Requested by the client on 2026-10-20. These are committed features, to be
built after the current module work (Profile → edit/delete lectures &
assignments). Each one must be real, tested for every role, and configurable
from the app — nothing hard-coded.

---

## 1. Email notifications — ✅ built 2026-10-20 (WhatsApp still optional)

**Goal:** every important event reaches students and teachers by email as well
as in the app (bell + inbox, which already works).

**Events to email** (all already create in-app notifications via `notify()`):

| Event | Who gets it |
|---|---|
| New lecture / resource / material uploaded | every student in the class |
| New assignment, new quiz | every student in the class |
| Assignment graded (marks + feedback) | that student |
| Quiz result | that student |
| Announcement posted / comment on your post | class / post author |
| Private comment on an assignment | the other side (student ↔ teacher) |
| Marked absent | that student |
| Student registered (needs approval) | teachers |
| Account approved | that student |
| Student submitted work | the class teacher(s) |

**How it will work**
- One hook: `notify()` in `includes/functions.php` also queues an email, so every
  module gets email automatically.
- **Email queue table** (`email_queue`) + a sender that runs from cron on cPanel
  (and a "send now" fallback), so pages never wait for SMTP.
- **SMTP settings in the app** (host, port, encryption, username, password,
  from-name, from-address) on a teacher settings page, with a **"Send test email"**
  button — never hard-coded in files.
- **Branded HTML email template** (class name, button back to the right page).
- **Per-user preferences** on the profile page: turn each email type on/off,
  or get a **daily digest** instead of one email per event.
- **Unsubscribe link** in every email; failed sends retried and logged.

**WhatsApp (optional, later):** Meta WhatsApp Business Cloud API — verified
business account, approved message templates, per-message cost, and a phone
number + consent per user. Only if the client wants it.

---

## 2. More than one teacher

**Done (2026-10-20):** teacher invites (Settings › Teachers), co-teachers per
class (People tab), teacher directory. The notes below are the original plan.

**Before:** many teachers could already use the system — each owned their own
classes, any teacher could approve students. **Was missing:**

1. **Adding teachers from the app** — today only `setup.php` (first teacher) or
   the database can create a teacher. Build: a teacher can **invite another
   teacher** by email/invite link, or create the account; new teachers set their
   own password.
2. **Co-teachers (several teachers in one class)** — like Google Classroom:
   - new `class_teachers` table (class_id, teacher_id, role: owner / co-teacher);
   - the owner invites co-teachers from the class **People** page;
   - co-teachers can post, add classwork, grade, take attendance; only the owner
     can archive the class or remove co-teachers;
   - all access checks move from `classes.teacher_id` to `class_teachers`
     (`getAccessibleClass()` in `includes/classes.php` is the single place);
   - "submitted work" / "student joined" notifications go to every teacher of
     the class.
3. **Teacher directory** — list of teachers, their classes, last activity.

---

## 3. Feature-rich roadmap (after 1 and 2)

- **AI** (see IMPROVEMENT_PLAN.md): quiz questions from a lecture, grading
  assistant, "explain my mistake", lecture study helper, class insights summary.
- **Calendar** of all due dates (assignments, quizzes) across classes.
- **Rubrics** for assignments; **reuse a post** from another class; **copy a class**.
- **Scheduled posts** (publish an announcement / assignment at a set time).
- **Bulk actions**: grade many submissions, message all missing students.
- **Exams & marks modules** (sidebar sections already reserved).
- **Mobile polish** and **dark mode** once the core is complete.
