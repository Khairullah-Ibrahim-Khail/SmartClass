# SmartClass — Product Audit & Improvement Plan (2026-10-20)

Benchmark: Google Classroom. Goal: a cohesive, reliable classroom product for
teachers and students — not a set of disconnected modules.

## Audit findings (evidence-based)

Checked: every page as teacher / student / outsider over HTTP, browser console
on key pages (only a missing favicon), screenshots at desktop and phone width,
and the code of every module.

### Broken or missing core workflows

| # | Finding | Impact |
|---|---|---|
| A1 | **Attendance is broken** — `markAttendance.php` queries tables/columns that do not exist (`students`, `attendance`, `roll_no`, `grade`). | Core teacher workflow unusable |
| A2 | **"View Students" is broken** — `viewStudents.php` uses the same old schema and a wrong include path. | Teachers have no roster |
| A3 | **Notifications are written but never shown** — no bell, no inbox. Students are never told about approvals, new work or grades. | Users miss everything |
| A4 | **The class page is not a classroom** — a list of "Open" links. No stream, no announcements, no discussion, no people/teacher view, no grades. | The heart of the product is missing |
| A5 | **No way to manage a class** — teachers cannot edit, archive, or reset the join code; students cannot leave a class. | Real classes change |
| A6 | **No edit/delete for lectures and assignments** — a typo is permanent. | Daily frustration |
| A7 | **No profile** — users cannot change their name, password or photo. "View Profile" is a dead link. | Basic expectation |
| A8 | **No gradebook** — grades are scattered over assignment and quiz pages. | Teachers need one view |

### Bugs

| # | Finding |
|---|---|
| B1 | Text is HTML-encoded on input by register / create class / join, then escaped again on output: `O'Brien` shows as `O&#039;Brien`. Existing rows are affected. |
| B2 | Class codes use `rand()` (predictable). |
| B3 | Logout is a GET link (any page can log users out). |
| B4 | Missing favicon (404 on every page). |

### UX / design inconsistencies

| # | Finding |
|---|---|
| C1 | Sidebar shows **8 unbuilt sections** (Exams, Marks, Research, Schedules, Forums, Events, Announcements, Settings) that only say "coming soon". Header shows "Attendance soon". Looks unfinished. |
| C2 | Dashboard shows a developer roadmap card ("System Modules · 7 of 13 live") to end users. |
| C3 | Two fonts: auth pages use Poppins, the app falls back to Times New Roman. |
| C4 | Class page repeats the class name twice (page title + banner). |
| C5 | No notification bell / unread count anywhere. |
| C6 | No dark mode (not supported today; out of scope unless requested). |

### Product decision needed

- **Administrators**: the agreed design is *teacher + student only, no admin*.
  The new brief mentions administrators. Not added — confirm before changing
  the role model.

## Plan (in priority order)

**Phase 1 — core workflows & bugs**
1. B1–B4 fixes (stop encoding on input + one-off data repair; `random_int`; POST logout; favicon).
2. Notifications: bell with unread count in the header, dropdown, full inbox page, mark read.
3. Class hub (Google-Classroom style tabs on the class page): **Stream** (announcements +
   comments + recent class activity), **Classwork** (lectures, resources, assignments,
   quizzes in one list), **People** (teacher + students, remove), **Grades** (gradebook),
   **Attendance**. Class settings: edit, archive, reset code, invite link. Students: leave class.
4. Attendance rebuilt on `class_members`: mark by date, student view, reports.
5. Students roster (rewrite of viewStudents).
6. Profile: name, password, photo.
7. Edit/delete lectures and assignments.

**Phase 2 — design system & navigation**
8. One font (Poppins) and shared tokens; sidebar shows only working modules; remove roadmap card.
9. Responsive + console sweep of every page, screenshots at desktop and phone width.

**Phase 3 — dashboard**
10. Quick actions, onboarding for empty accounts, per-class progress, gradebook CSV export.

Each step is tested over HTTP for every role before the next starts.

## AI features (requested 2026-10-20 — planned, not built yet)

Principle: AI **drafts, the teacher decides**. Nothing AI-generated is saved,
graded or sent to students without a teacher reviewing it first. Every call is
server-side, scoped to one class, logged, and rate-limited per user.

Where it fits, in order of value:

| # | Feature | Page | Who |
|---|---|---|---|
| AI1 | **Generate quiz questions from a lecture** — pick a lecture, get draft multiple-choice questions that fill the existing builder for the teacher to edit | `pages/quizzes/create.php` | Teacher |
| AI2 | **Grading assistant** — suggested marks + feedback for a submission against the assignment instructions; teacher accepts/edits in the existing grade form | `pages/assignments/view.php` | Teacher |
| AI3 | **Explain my mistake** — after a quiz, an explanation of why the right answer is right, for questions the student got wrong | `pages/quizzes/view.php` (review) | Student |
| AI4 | **Lecture study helper** — summary / key points / "ask about this lecture", answering only from that lecture's text | `pages/lectures/view.php` | Student |
| AI5 | **Lecture & announcement drafting** — outline or improve a draft | `pages/lectures/add.php`, class stream | Teacher |
| AI6 | **Class insights** — students at risk (missing work, low quiz scores, absences) with a plain-language summary | Dashboard / class Grades tab | Teacher |

Infrastructure (one place, like the rest of the app):
- `config/db.php` config block: `AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL` (empty key = AI buttons hidden).
- `includes/ai.php`: one helper that sends a prompt and returns text/JSON, with timeout,
  error handling and an `ai_usage` log table (user, feature, tokens, time).
- Privacy: send content only (lecture text, questions, answers) — never emails or other students' data.
- Build after Phase 1 so AI sits on top of working workflows (AI1 + AI2 first).

## Progress log

### 2026-10-20 — Phase 1, steps 1–3 done (tested over HTTP for every role + browser checks)

- **Step 1 (B1–B4):** input stored raw + escaped on output (migration 003 repairs
  old rows); `random_int()` class codes; logout is POST + CSRF only; favicon.
- **Step 2 (Notifications):** header bell with unread badge + dropdown, inbox page
  (`pages/notifications/`), mark read / mark all read; links validated so a
  notification can never redirect off-site.
- **Step 3 (Classes):** class hub tabs **Stream** (announcements + comments +
  auto-posts + Upcoming), **Classwork** (all work in one list + Create menu),
  **People** (invite code/link, remove, leave), **Grades** (gradebook + CSV
  export with formula-injection protection); **Class settings** (edit, reset
  code, archive/restore); invite links pre-fill the join form; archived classes
  listed in My Classes. Migration 004 (announcements, comments).
- **Security fixes found during this work:** SweetAlert titles built from user
  names (stored XSS against teachers) → `titleText`; join-page preview used
  `innerHTML` with class/teacher names → `textContent`; class image URL limited
  to http(s); header loop variables namespaced after a variable collision
  emptied the notifications page.

### Next
Step 4 Attendance (+ class Attendance tab) → 5 Students roster → 6 Profile →
7 edit/delete lectures & assignments → Phase 2 design system → Phase 3 dashboard → AI.

### 2026-10-20 — Classes module completed (modular)

- Class sections split into their own pages (stream / classwork / people /
  grades / insights) sharing `includes/class_header.php`; class helpers moved to
  `includes/classes.php`; shared JS in `assets/js/class.js`; old
  `view.php?id=&tab=` links forward. Migration 005 (topics, pinned posts,
  stream permission, theme, private assignment comments).
- Advanced features: topics, pinned posts, student posting setting, private
  comments, student progress page, class insights, colour themes, redesigned
  My Classes cards.
- UI: Poppins font, width-limited pages centred, header never widens the page
  (compact under 1500px, icons under 1300px — all links kept), no horizontal
  overflow from 320px to 1920px on any page, theme swatch contrast.
- Nothing visible was removed: the earlier hiding of "coming soon" links, header
  Attendance link, View Profile and System Modules card was reverted at the
  user's request.

### 2026-10-20 — Attendance done (Phase 1 step 4)

- Migration 006 (`attendance`, statuses per the design: present / late / absent /
  official present). Class Attendance page (sheet by date, mark all present,
  remarks, edit/clear a day, report with rates, CSV). The broken
  `pages/attendance/markAttendance.php` was rebuilt in place; new
  `pages/attendance/view.php`. Sidebar Mark/View Attendance and the header
  Attendance link now work (they were "soon"). Attendance feeds Insights
  (below 75% = needs attention) and the student progress page.

### Next
Step 5 Students roster (rebuild `viewStudents.php`) → 6 Profile → 7 edit/delete
lectures & assignments → AI features.

## Notification delivery channels (asked 2026-10-20 — planned)

Today notifications are **in-app only** (header bell, dropdown, inbox).
- **Email** (recommended next): hook into `notify()` so every notification can also
  be emailed; SMTP settings editable from a settings page (not hard-coded); per-user
  opt-out; send in the background or batch so pages stay fast.
- **WhatsApp** (optional): Meta WhatsApp Business Cloud API — verified business,
  approved message templates, per-message cost, and a phone number per user
  (new `users.phone` column + consent). Only if the client wants it.

### 2026-10-20 — Phase 1 steps 5–7 done

- Students roster rebuilt in place (`viewStudents.php`): search (wildcards literal),
  class filter, per class grade / attendance / missing work, link to progress.
- Profile (`pages/profile/`): name + email, photo (content-checked, served by
  `photo.php`, shown in the header), change password. "View Profile" and
  "My Profile" links now work.
- Edit/delete: lectures (`add.php?id=`) and assignments (`create.php?id=`, max marks
  never below a given mark, unchanged past due date kept, students told when the
  due date moves); delete removes submissions and their files.

### Next (agreed)
Email notifications + multi-teacher (docs/FEATURE_NOTES.md), then AI.

### 2026-10-20 — Teacher approval to join + attendance rules

- Migration 007: `classes.require_approval` (default on), `class_join_requests`
  (`class_members` stays approved-only, so grades / attendance / stream never
  include pending students).
- Class code / invite link → join request; teacher approves, declines or
  approves all on the class People page; student notified either way; My
  Classes shows waiting requests (cancel) and a "join requests" badge for
  teachers. Registering with a class code sends account + class approval to
  that class's teacher in one step; Approve Students shows the requested class.
  Invite links survive login/registration. The old AJAX joinClass can no longer
  bypass approval.
- Attendance: every student on the sheet must get a status before it saves;
  students are only on the sheet for days on/after they joined.

### 2026-10-20 — Email notifications done

- Migration 008 (app_settings, email_queue, email_preferences, users.email_token).
- `includes/settings.php` (DB settings, AES-256-GCM secrets) and `includes/mailer.php`
  (SMTP client with SSL/STARTTLS + AUTH LOGIN, PHP mail() option, HTML + text
  template, queue with retries, daily digest, unsubscribe links). `notify()` queues
  an email automatically, so every module emails without changes.
- Settings › Email Settings (teacher): account, sender, site URL, delivery
  (cron / immediate), test email with the exact error, queue stats, retry failed.
  Profile: instant / daily / off + 5 categories. One-click unsubscribe page
  (GET confirms, POST unsubscribes). `cron/send_emails.php` (CLI only).
- Tested against a local SMTP server: auth failure, delivery, MIME/encoding,
  preferences, digest, unsubscribe, immediate mode, failures + retry, header
  injection. Not yet tested against a real provider with TLS — do a "Send test
  email" with the client's real account before going live.

### 2026-10-20 — Class page: A1–A6 of ROADMAP_TODO.md done

- **A1 Messages tab** (migration 009 `private_messages`): one inbox per class for
  every private conversation — general ("Message teacher"), per assignment, per
  quiz. Unread badge on the tab and the class cards; "Seen / Sent"; old
  assignment comments copied in.
- **A2 Overview strip** on every class page. Teacher: students + join requests,
  class average, today's attendance, work to grade. Student: my grade, my
  attendance %, missing work, next due. Same numbers as Grades / Insights.
- **A3 Due this week** card on Classwork (next 7 days, Today / Tomorrow / weekday).
- **A4 Edit stream posts** (migration 010 `announcements.edited_at`): author only,
  inline edit form, inline validation, "Edited" marker; pinning does not count as an edit.
- **A5 Calendar tab**: month view of assignment / quiz due dates and attendance
  days (teacher: turned-in / present counts; student: own status). On phones it
  becomes a list of the days that have something.
- **A6 Classwork** (migration 011 `content_progress`, `assignment_materials`):
  students mark lectures / resources done; opens are recorded (lecture page view,
  resource download); teacher sees "opened x/y" chips and a per-item roster
  (`pages/classes/activity.php`); search box; collapsible topics (remembered per
  class); assignments can carry class resources + up to 5 http(s) links.
- Fixed: page wider than the screen at tablet widths (`.main-wrapper` min-width).
- Demo data: `php config/seed_demo.php --progress` adds lecture progress + links.

- **A7 People** (teacher): each student's overall grade, attendance, missing work
  and last activity in the class; sort by any of them; roster CSV
  (`roster_export.php`, formula-safe). Fixed "1 days ago" wording everywhere.
- **A8 Grades**: click a turned-in assignment cell to grade it in a dialog (same
  rules + notification as the assignment page — both use `gradeSubmission()`);
  score colour scale with legend; sort by any column.
- **A9 Stream** (migration 012 `publish_at`, `notified_at`): links in posts and
  comments are clickable (`linkify()`, escaped, new tab); teachers can schedule a
  post — hidden from students (and closed to comments) until then, notified once
  when due by `cron/send_emails.php` or the first class-page visit; "Post now".

Phase A is complete.

### 2026-10-20 — Phase B: more than one teacher

- **B1 Teacher invites** (migration 013 `teacher_invites`): Settings › Teachers —
  invite by name + email; one-time link valid 7 days (only its SHA-256 is stored,
  shown once, emailed when email is set up); "New link" / cancel by the inviter;
  `pages/auth/teacher_invite.php` lets the invitee choose a password (claimed
  atomically, works once). Teacher directory with classes / students / last login.
- **B2 Co-teachers** (migration 014 `class_teachers`, existing teachers become
  owners): the owner adds / removes co-teachers on People; a co-teacher can step
  down. Co-teachers do everything except archive / restore and manage co-teachers.
  All teacher access now goes through `class_teachers` (`getAccessibleClass`,
  `getUserClasses`, `teachesClassSql()`), including dashboard, AJAX, downloads and
  edit pages. Student actions (submissions, quiz results, messages, join requests,
  posts) notify every teacher of the class. Stream auto-posts name the real author.
  Message read state treats the teachers as one side.

Phase B is complete.

### 2026-10-20 — Phase R: roles, privacy and sign-in (decided by the client)

- **R1 Super admin** (migration 016 `users.is_admin`, `isAdmin()` / `requireAdmin()`):
  only the super admin invites teachers, sees the teacher directory and changes Email
  (and later AI) settings / backups; admin menu items are hidden from other teachers.
  setup.php makes the first teacher it creates the super admin.
- **R2 Teachers see only their own data**: Approve Students, dashboard pending /
  activity and registration notifications are scoped (`getPendingStudentsFor()`,
  `notifyAdmins()`); accounts without a class request go to the super admin only;
  a teacher's "Reject" declines only their own class requests; profile photos are
  served only to people who share a class (`canSeeUser()`).
- **R3 Co-teachers added by typing the colleague's email** — no list of teachers shown.
- **R4 Join requests** show photo, email, sign-up date, email verified, number of
  classes; a student who is still waiting sees only the class name (no teacher,
  image or description) in the code preview, My Classes, the waiting page and
  registration messages.
- **R5 Students sign in with a one-time email code** (migration 017 `login_codes`,
  `users.email_verified_at`, `includes/login_codes.php`, `pages/auth/code_login.php`):
  6 digits, HMAC stored, 10 minutes, 5 tries, 3 codes / 15 min, same message whether
  the email exists or not; registering sends the first code (no password). While email
  is switched off the old password forms are used, so nobody is locked out.
- **R6 Sidebar**: Classes, Lectures, Resources, Assignments, Quizzes, Attendance and
  Notifications are direct links (current one highlighted); Students, Settings and
  the coming-soon modules keep their dropdowns.

Phase R is complete.

### 2026-10-20 — C1 AI Settings (super admin)

- Migration 015 (`ai_providers`, `ai_usage`); `includes/ai.php`: one `aiChat()` for
  OpenAI, Anthropic Claude, Google Gemini, Groq, Mistral, DeepSeek, OpenRouter, Ollama
  and any OpenAI-compatible API (three API styles). Per-feature provider, fallback
  provider, one retry on network / 429 / 5xx, JSON answers extracted and validated,
  monthly token limits (per person / school), usage + cost log. Adapts once when a
  model refuses a parameter (temperature, max_tokens vs max_completion_tokens,
  response_format). Keys encrypted, only the last 4 characters shown, sent only in
  the provider's header (Gemini key never in the URL).
- `pages/settings/ai.php` (Settings › AI Settings, super admin): on/off, default /
  fallback / per-feature provider, limits, timeout, students on/off; per provider:
  key, model (loaded from the provider's model list or typed), address, prices,
  Test connection, Load models, Remove; usage this month + latest calls.
- Tested against a local mock of all three API styles (wrong key, models list,
  JSON, prose-wrapped JSON, refused parameter, 500 → retry → fallback, limits).
  **Not yet tested against the real providers** — use "Test connection" with real keys.

### 2026-10-20 — Sidebar fixes, demo admin, personal AI (client requests)

- **Sidebar**: direct links did not navigate (the design script cancelled every
  menu-header click) — fixed; `assets/js/sidebar-state.js` keeps the sidebar's scroll
  position between pages, opens the section of the current page and keeps the current
  link in view (tested with real clicks in Chrome).
- **Demo super admin** `admin@test.com` / `password` shown on the login screen (only
  while that demo account exists). The real admin account is never shown.
- **Personal AI** (migration 018): Settings › My AI for every teacher and student — own
  provider + key (used first, paid by them, not limited by the school). Teachers can
  share theirs with students of their classes with a monthly limit per student; turning
  it off (or sharing off) stops students at once. Per class "AI for students" switch
  (class settings, off by default) — when off, no student AI in that class, not even
  with their own key. Order for a student: own key → teacher's shared key → school's.
- **Simpler provider setup**: no token prices; saving the key loads the provider's
  model list automatically (chat models only) and the model is picked from a dropdown.

- **Sidebar jump, measured and fixed**: after clicking a dropdown item the new page
  showed the sidebar at the top with the section closed, then animated it open and
  jumped twice (≈0.5 s). Now a small script right after the sidebar (app_header.php)
  opens the current section at full height without animation and restores the scroll
  position before the first paint; dashboard.js registers that section as the open one.
  Measured again: the first frame of the new page is already final. Collapsed sidebar
  and narrow screens keep the design's floating menus.

### 2026-10-24 — Phase L: lectures rebuilt (L1–L8)

- **Writing** (`pages/lectures/add.php`, `assets/js/lecture-editor.js`): two-column editor —
  title, one-line summary, content with a formatting toolbar (H2/H3, bold, italic, code,
  colour, link, lists, quote, line, image, code block with language; Ctrl+B/I/K; Tab
  indents inside code), Write / Preview (same renderer students see), word / reading-time
  count, local backup of unsaved text with Restore; Publish now / Schedule / Save as draft;
  main video, class files + links / videos as materials.
- **Renderer** (`includes/lectures.php`, `renderLecture()`): safe Markdown subset — everything
  escaped, http(s) links only; old lectures render as before. Colours from a fixed palette
  (`{red}…{/red}`, `{mark}…{/mark}` highlighter).
- **Publishing** (migration 019): draft / scheduled / published; students see and are
  notified only when published (once — `publishDueLectures()` from class pages + cron).
  Drafts hidden from students everywhere (list, classwork, stream, dashboard).
- **Lecture page**: title once, breadcrumb, reading time, videos (YouTube embedded),
  contents ("On this page"), teacher notes, materials, previous / next, mark as done /
  who opened it, "Ask your teacher" (Messages, new 'lecture' context).
- **Lecture list**: search, class / topic / status filters, grouped by class + topic,
  drafts / scheduled badges + reach for teachers, done / new + progress bar for students.
- **"Explain this code" (AI)** on every code block, like DataCamp: step-by-step explanation
  under the block; follows the class AI switch and own / shared / school key rules; only
  code really in the lecture is sent (as data); cached per block for the whole class.
- **Images** (migration 020, `pages/lectures/images.php`, `image.php`): upload by button,
  drag & drop or paste; type checked from content, re-encoded with GD (metadata and hidden
  code removed, max 1600 px), random names, served only to the class. In Preview: drag to
  move, up / down, align left / centre / right (text flows around), size slider, caption,
  remove; class image library with delete.
- Tested by HTTP, in real Chrome (toolbar actions, drag & drop) and against hostile files.
  **On this local Apache, image uploads need `ReadWritePaths` for uploads/ (see README)** —
  until then the editor shows a clear message.

### 2026-10-24 — C2 AI quiz generator

- **Create Quiz › "Generate questions with AI"** (shown when the teacher has AI — own key or
  the school's): tick lectures of the class and/or type a topic, choose number (1–20) and
  difficulty. `pages/quizzes/generate.php` → `generateQuizQuestions()` (`includes/ai_quiz.php`).
- Questions arrive in the builder as normal, editable questions with an "AI draft — check
  it" mark (it disappears when the teacher edits that question). **Nothing is saved or
  published until the teacher presses Publish Quiz.**
- Safety: only text of the chosen lectures of THAT class is sent, as data in the user
  message (never in the instructions); no names / ids. Answers must be JSON; every
  question is validated (2–6 distinct options, valid correct index) — unusable ones are
  dropped and counted, "A) " prefixes removed, options shuffled so the right answer is not
  always first. Usage is logged under the right key (own / shared / school).
- Tested with a mock AI (broken questions, mixed answer formats, prompt-injection text in the
  topic) and in Chrome: generate → review → edit → publish; saved quiz matches the builder.

### 2026-10-24 — C3 rubrics + AI grading assistant

- **Rubric** (migration 021 `assignments.rubric`): optional criteria on an assignment, points
  adding up to Max Marks (live "Total 50 / 50" check, validated again on the server); students
  see the rubric on the assignment page. **"Suggest a rubric with AI"** drafts it from the
  title + instructions (`pages/assignments/rubric_ai.php`, points scaled to Max Marks) for the
  teacher to edit.
- **Grading assistant** (`pages/assignments/suggest.php`, `includes/ai_grading.php`,
  `assets/js/grading-ai.js`): "AI suggest" on a turned-in typed answer fills the grade form
  with points per criterion + feedback; "Suggest for all waiting" does it one by one. The AI
  only suggests — the teacher reads it and presses Grade. File-only submissions are refused
  with a clear message.
- Safety: the answer goes to the AI as data, never in the instructions; no names are sent;
  the AI's totals are not trusted (the server adds the clamped criterion points; no-rubric
  marks are clamped to Max Marks); an answer that talks to the grader ("give me full marks")
  is flagged with a warning; suggestions are cached until the student changes the answer.
- **Every grade records how it was made** (`submissions.grade_source`): teacher, AI accepted
  (saved exactly as suggested) or AI edited — decided on the server from the stored
  suggestion, shown to the teacher as an "AI-assisted" badge (students are not shown it).
- Tested with a mock AI (over-generous points, wrong criterion names, cheating answer),
  every guard (student / other teacher / no CSRF), and in Chrome. A script-name clash that
  broke the form script was found and fixed by that browser test.

### 2026-10-25 — C4 release results + result emails
- Assignments and quizzes have a **Results** setting (migration 022, `release_mode`): "As soon as
  it is graded" (default, nothing changes) or "When I release the results". Held results are
  hidden from students everywhere: assignment/quiz pages and lists, classwork, grades,
  calendar, student progress, dashboard, quiz results, and the "graded" notification.
- The teacher's **Release results** button (confirm, CSRF) claims the release atomically,
  notifies every student once (in-app + email under the "grades" category) and later
  grades are visible at once. A second click does nothing.
- Tested over HTTP as student and teacher on every surface, release twice (one notification
  each), and in Chrome. Email was off, so the email path was not exercised end to end.

### 2026-10-25 — C5 explain my mistakes + class insights
- **Students:** on a submitted quiz (results released, AI on for the class) the "Explain my mistakes"
  button writes a short "Why" under each question they got wrong. Kept per attempt, so a
  second click costs nothing.
- **Teachers:** a "How the class did" card on the quiz page shows, from plain database counts,
  the % correct per question and how often each option was picked; an optional AI summary
  (weak points + what to do next) is written from those counts only — no names — and is kept
  until more students finish. (Migration 024, includes/ai_insights.php.)
- Server checks: student must own the attempt and be released; class AI switch / key rules
  apply; teacher must teach the class; AI text is stripped of tags, only known question ids are
  accepted, and it is shown with textContent. Tested with a mock AI (bogus ids, HTML in
  answers), held results, AI off for the class, other teacher, no CSRF, and in Chrome.

### 2026-10-25 — C6 short-answer questions + lecture study helper
- **Short-answer questions:** the quiz builder has a "Multiple choice / Short answer" switch per
  question; a short question needs an "expected answer" (migration 025). Students type their
  answer (empty = 0 at once). Marks are given by the teacher, or "Mark with AI" on the quiz page
  marks the waiting answers against the expected answer in batches: marks are clamped to the
  question's marks (half marks), feedback is plain text, and an answer that tries to influence the
  marker is not marked but left for the teacher. Every AI mark is labelled "AI-marked — check" and
  can be changed. The attempt's score is re-added after every mark; students see "waiting to be
  marked" until then, and nothing at all while results are held. Explain-my-mistakes and the class
  statistics ignore short questions. The AI marking runs on the teacher's AI (own key or school).
- **Study helper (students):** a panel on the lecture page — Summarize, Key terms (saved per
  lecture, rewritten when the text changes) and "Ask about this lecture" (answered from the lecture
  only, not saved). Migration 026; same class switch / key rules as the other student helpers.
- Tested over HTTP (create + validation, student submit with HTML in the answer, AI marking with an
  over-generous mark and a prompt-injection answer, manual marks, forged ids, student / other
  teacher, held results, AI off) and in Chrome. Also fixed a PHP warning on the assignment list.

### 2026-10-25 — C7 remaining AI helpers (Phase C complete)
- **Teachers** (one endpoint, pages/ai/assist.php, drafts only — nothing is saved or sent by the AI):
  announcement writer + Translate (English / Pashto / Dari / Arabic / Urdu) on the class stream;
  assignment-instructions writer; lecture drafter (in the lecture editor); "Improve wording" for
  grading feedback (grades popup + assignment grading rows); "Suggest a reply" in private messages
  (the thread is read on the server). Every draft appears in the field with an Undo link.
- **Students:** "Practice quiz" on a lecture (4 questions, checked in the browser, not saved or graded)
  and "Plan my week" on the dashboard (from their own open work; only titles, classes and due dates are sent).
- Same rules as every AI feature (class switch, own / shared / school key); inputs are sent as data;
  other teachers' classes, students and missing CSRF are refused. Tested over HTTP and in Chrome.
- Provider errors no longer show the masked key that some providers echo back.

### 2026-10-25 — D1 forgot password
- "Forgot password?" on the login page (only when email is on) → `forgot_password.php` asks for the
  email and always answers the same, so nobody can test which emails exist. The account gets a
  one-time link (random 256-bit token, only its SHA-256 stored, 60 minutes, max 3 per hour, a newer
  link cancels older ones). `reset_password.php` shows the form (opening the link changes nothing,
  no referrer is sent), inline validation, the link is claimed atomically when the password is
  saved, every other open link of that account ends, and the owner gets a "password changed" email.
  Migration 027. `cron/reset_password.php` (command line only) is the way back in when email is off.
- Tested over HTTP with a local SMTP sink (unknown / invalid / known email, bad / reused / expired
  token, mismatched / short / empty passwords, wrong CSRF, rate limit, old password rejected and new
  one accepted) and in Chrome. The email settings I switched on for the test were removed again.

### 2026-10-25 — D2 backup + restore (Settings › Backup, super admin only)
- **Download:** one compressed SQL file of all tables, written by PHP (no mysqldump needed on shared hosts);
  no USE / CREATE DATABASE in it. Uploaded files in `uploads/` are not part of it (said on the page).
- **Restore:** upload the file + your password + type RESTORE + a confirm dialog. The file is checked
  BEFORE anything is touched: it must be a SmartClass backup and may contain only SET / DROP TABLE IF EXISTS /
  CREATE TABLE / INSERT INTO statements on known tables, each exactly one statement (a `;` outside quoted text
  is refused). A safety backup of the current data is written to `backups/` (web access denied, newest 5
  kept) and, if the restore fails half-way, it is put back automatically. You are signed out afterwards.
- Found and fixed while testing on a throwaway copy: stacked SQL (`INSERT …; DROP TABLE users`) slipped past
  my first check; and a rollback refused to recreate a table the failed restore had dropped.
- Tested on a mirror app + throwaway database (never the live one): all 39 table checksums identical after
  restore (except the activity log, which records the restore itself), refusals for wrong password / missing
  RESTORE / garbage / `DROP DATABASE` / stacked / unknown-table files touch nothing, failed restore rolls back
  to identical checksums, restoring a missing table works, teacher and anonymous users are refused.

### 2026-10-25 — D3 bulk actions
- **Assignment page:** tick submissions (or "select all") → "Give marks to selected": marks + one feedback for
  all, through the same gradeSubmission() as grading one by one (so notifications, held results, grade source
  and checks are unchanged); max 200 at once; ids from the browser are checked against this assignment and
  class. **"Remind N students who did not hand in"** (assignment and quiz pages): an in-app notification (and
  email if the student chose that) with an optional note; anyone reminded about the same item in the last
  6 hours is skipped. New notification type work_reminder. No migration.
- Tested: 3 of 4 graded in one go with notifications, marks over the maximum refused, a submission of another
  assignment / no CSRF / student / other teacher all change nothing, reminders go only to students who have not
  handed in, a second click is skipped, quiz reminders; Chrome checks of selection, dialogs and validation.

### 2026-10-25 — D4 copy a class / reuse a post
- **Copy class** (Class settings › Copy class, any teacher of the class): new name, tick lectures / assignments /
  quizzes / resources. Always copied: description, tags, banner, theme, stream and joining rules, AI switch, topics.
  Lectures arrive as drafts (nothing is published or announced), assignments and quizzes without due dates and
  with results not released; rubrics, questions, options, expected answers kept. Files are copied under new
  random names (a file that cannot be copied is left out and counted); lecture pictures in the text and resource
  attachments are re-pointed to the copies. Never copied: students, requests, submissions, grades, attempts,
  messages, the stream. New join code; the copier is the owner. Everything is one transaction.
- **Reuse a post** (↗ on a teacher's stream post): post a copy now in another of the teacher's own active classes
  (or again in this one); that class's students are notified.
- Tested on a mirror (so files could be written): counts of lectures / questions / options / topics equal to the
  source, no members or submissions, drafts and empty due dates, image id 5→6 with a new file, resource
  attachments mapped, a missing file dropped cleanly, empty-name validation; reuse to own class works, other
  teacher's class / another class's post / student change nothing; Chrome checks of both. All test rows removed.

### 2026-10-25 — D5 dark mode + mobile check
- **Dark mode:** a moon / sun button in the header switches the whole app (click = light ↔ dark, double click =
  follow the system again); the choice is kept in this browser and applied before the first paint, so there is
  no flash. `assets/css/theme-dark.css` = a hand-written part (variables, inputs, tables, dialogs, footer) plus
  overrides GENERATED from the light styles (every light background, dark text colour and light border in
  dashboard.css / app.css gets a dark counterpart), so new light styles are easy to cover by regenerating.
  The sign-in pages stay light.
- **Mobile:** measured 375 px wide in Chrome, light and dark, on ~35 teacher / student / admin pages: no page
  scrolls sideways (wide tables scroll inside their own box). The header's theme button is icon-only so the
  header does not overflow.
- Tested in Chrome: toggle, persistence across reload, back to system setting; screenshots of the dashboard,
  class pages, quiz, assignment grading table, gradebook, lecture, AI / backup settings in dark.

### 2026-10-25 — UI pass: instant navigation + one look (user feedback: "every action reloads the page, UI inconsistent")
- **No more full reloads** (`assets/js/ajax-nav.js`, loaded on every app page): links, forms (also the ones
  submitted after a confirm dialog), filters and the back / forward buttons fetch the page in the background and
  swap only the page area; the sidebar, header, bell and theme stay. Page scripts (`<script data-page>`) run
  again after each swap, listeners they add to document / window are removed at the next swap, DOMContentLoaded
  callbacks run at once, downloads are saved as files, saving a form on the same page keeps your scroll
  position, a thin progress bar shows work, submit buttons are disabled while sending. Anything unexpected
  (login page, error, non-HTML) falls back to a normal page load, so nothing depends on it.
- **One look** (polish layer at the end of app.css): one font (Poppins) everywhere (it fell back to Times New
  Roman in places), forms left-aligned with one maximum width, no browser-default blue underlined links in
  pages, calmer focus rings and cards, a header that no longer cuts off the profile name (quick links show
  icons only on mid-size screens).
- Tested in Chrome: sidebar / in-page links, form posts with redirect + flash dialogs, confirm-dialog deletes, GET
  filters, back / forward, and that quiz builder, lecture editor, stream, gradebook, bulk and reuse scripts
  still work after a swap.
- Mistake made and fixed: a browser test clicked the first delete button on the Announcements list and removed a
  real pinned announcement (#66) with its 3 comments; restored from the 24 Oct dump (see memory note).

### 2026-10-25 — Professional look (user: "make the UI polished and professional") + full-width forms
- New `assets/css/polish.css` (loaded after app.css, before the dark theme): a calm light canvas, solid accent
  colour instead of gradient buttons and gradient page titles, deep-navy sidebar with a single accent for the
  active item, white header with a hairline border, one card style (border + soft shadow), consistent 10 px
  controls with focus rings, tidier tables, badges, empty states, dialogs and dropdowns. Dark mode was
  regenerated from it (gen_dark.py now reads polish.css too).
- **Forms use the full page width** (user's suggestion): two columns on wide screens (long text fields span both),
  one column on phones; popups stay for quick confirmations / one-line questions.
- New modules built on it: Announcements (several classes at once, scheduled, pinned), Events, Schedules
  (weekly timetable with overlap checks for the class and for the teacher).
- Checked: ~70 pages as teacher / student / admin with no PHP errors; light and dark screenshots; mobile 375 px.

### 2026-10-25 — D6 modules (status when work stopped)
- **Done + tested over HTTP, with cleanup:** Exams + Marks (migration 029: exams scheduled and edited, marks grid with
  absent / remarks, publish / hide results with one notification each, student sees only own mark + class average,
  CSV), Forums (migration 030: topics, replies, pin / lock / delete rules, notifications, search), Research
  (migration 030: library with type / search filters, http(s)-only links), plus Announcements, Events, Schedules.
- **Written, NOT tested:** Reports (teacher: class summary + per-student table with risk flags + CSV; student: "My
  report"). Only syntax-checked. Next session: open /pages/reports/index.php as teacher and student, compare the
  numbers with the Grades tab, try the CSV.
- Not done yet: Chrome (JS) tests of the new module pages' form validation (server validation was tested);
  D7 WhatsApp (optional, needs a Meta Business account).

### 2026-10-25 (later) — Reports tested
- Teacher report of a class (summary cards, per-student attendance / work handed in / quizzes / average, risk
  flags) — the class average (81.6%) equals the Grades tab; CSV works (formula-safe); a class that is not the
  teacher's falls back to their own first class and its CSV redirects; students are refused the CSV and see only
  their own "My report" per class. D6 is complete.

### Next
D7 (optional WhatsApp, needs a Meta Business account). Then a final pass: full smoke test of every page, a Chrome
sweep of the new module forms in light / dark / mobile, and a review of this file against the roadmap.
(Old line follows, kept for history:) Test Reports, then D7 (optional). Then a final pass: full smoke test of every page, a Chrome sweep of the new forms
in light / dark / mobile, and a review of this plan file against the roadmap.

### 2026-10-25 (final pass) — sweep
- Smoke test: all 53 app pages load without PHP errors as teacher, student and admin.
- Chrome sweep of the new module pages (announcements, events, schedules, exams, marks, forums, research, reports):
  no JavaScript errors and no sideways scrolling in light 1300 px, dark 375 px (mobile) and student dark 1300 px.
- D7 (WhatsApp) is deliberately NOT built: it needs a Meta Business account and a verified number, and an unverified
  sender would be a fake feature. When the account exists: settings page for token / phone-number id, a sender
  next to the email queue, per-user opt-in with number, template messages for the same notification types.

### 2026-10-25 (evening) — Multi-customer hosting (Phase M) — see docs/CUSTOMERS.md
- One code folder, many customers: `config/db.php` picks the database, uploads / backups / sessions folder and settings by the
  request's domain (`config/instances/<domain>.php`); everything else is the original system. Per-customer session cookie +
  folder + an instance check inside the session; a stray domain can be refused with `master_hosts`.
- Each customer: own database + own database login (can open only that database), clean schema without demo data
  (`config/install_schema.sql`, built by `bin/build_install_schema.php`), first admin with a one-time temporary password,
  first-sign-in terms screen + new password, editable school name / logo / colour (Settings › School), no demo logins on the sign-in page.
- Your control (Customers page, original system only): list with counts, pause / resume (a paused site still lets the admin download a backup,
  restore blocked), service switches (AI, email, uploads, sign-ups; enforced in the code paths and kept in YOUR database), time-limited
  logged support access, create (web form or `bin/create_customer.php`), delete with a final backup. `customer_events` is the log.
- Operations: migration runner with tracking (`schema_migrations`, `bin/migrate.php`), `bin/update_all.php` (maintenance page + backup + migrate per customer,
  failures reported and isolated), `bin/backup_all.php`, `bin/delete_customer.php`.
- Tested: two customers + the original system side by side — separate data, a customer's database login is refused on the others and the
  provider's read-only login cannot read users or support secrets, session ids do not work across customers, terms flow, pause / resume / services /
  support link (one use, tampered, expired, wrong-customer, expired session), update-all with a good and a failing migration, delete with final backup,
  and a regression smoke test of all 56 pages of the original system. Test customers and data were removed again.
- Not covered: web-form customer creation on this machine (Apache cannot write under /home — needs a real server), customer approval of support access
  (optional idea), per-customer limits (max teachers / students), WhatsApp.

### 2026-10-25 (night) — Uploads fixed + System check + Export PDF on every page
- **Why uploads failed:** the local Apache service runs with systemd `ProtectHome=read-only`, so it could not write anywhere under /home
  (the project is there). Fixed with `sh bin/fix_local_apache.sh` (drop-in `ReadWritePaths` for uploads / backups / sessions / logs / instances +
  owner www-data). Verified through Apache: profile photo (re-encoded, served only through PHP, direct URL 403, a PHP file disguised as an image refused),
  resource upload + student download (an outsider is sent to login, direct URL 403, disguised PHP refused).
- **Settings › System check** (super admin): real write test per folder as the web server, PHP extensions and upload limits, and a **files ↔ database sync check**:
  rows whose file is missing, and files no row points to, with a safe "Remove unreferenced files" button (recomputed on the server, CSRF, never touches a referenced file).
- **Export PDF** (header button on every page, `assets/js/pdf-export.js` + print styles): a dialog to customise paper (A4 / A3 / A5 / Letter / Legal), direction, margins,
  title, header (logo, school, title, date, user), footer with page numbers, colour or black & white, tables fit to width, and WHICH PARTS of the page to include; then
  "Save as PDF" in the print window. The result has real selectable text (Pashto / Dari included), no sidebar / buttons, header repeats the school branding, always light.
  Choices are remembered. Plain Ctrl+P also prints cleanly. Tested: A4 landscape and portrait, Letter, B/W (no CSS filter — a filter would turn text into a picture),
  chosen sections, with the pdf text checked by pdftotext.

### 2026-10-25 (night, later) — per-customer limits
- Customers page: maximum teachers / students / classes per customer (migration 032, empty = none), shown with current usage. Enforced in the customer's copy
  (read from the provider's database like the other switches): new student sign-up, accepting a teacher invite (open invites count), creating or copying a class.
  Tested with a temporary customer: class, copy, student, teacher-invite blocks with the message; raising / clearing the limit takes effect; the original system has no limits.
- Customer creation / deletion from the web page works now that Apache can write (needs `config/instances` writable: re-run the updated `bin/fix_local_apache.sh`).
  Creation checks the folders first and removes what it made if it fails.

### 2026-10-25 (late) — user review: dashboard, header flicker, approvals
- **Header flicker (found by recording the header while navigating in Chrome):** every page swap rewrote the bell icon + dropdown (icons re-parsed = flash), and the page
  scrollbar appeared / disappeared on longer / shorter pages (10 px sideways jump of the whole header). Fixed: the bell is only touched when its content really changed;
  the scrollbar space is always reserved. Re-recorded: zero header changes through classes, tabs, Students menu, approvals and back to the dashboard.
- **Approvals:** the dashboard count (6) and the approval list agree (6 accounts waiting, shown to the super admin). What was missing was *who they are*: names on the list
  and in the dashboard panel now open **Students › Review** (photo / initial, email, registered date, email confirmed?, last sign-in, the classes they asked to join with code and
  teacher) with Approve / Reject. Scope is unchanged: a teacher only reviews students who asked for one of THEIR classes and sees only those classes; the admin sees every waiting or
  rejected account. Tested with throwaway accounts (scope, approve, reject declines only the teacher's own request, student refused).
- **Dashboard redesign:** greeting + quick actions, six flat KPI tiles, "Needs your attention" (counts with links), today's timetable, coming up (deadlines + exams + events),
  the role panels (approvals / grading, or grades / resources), recent activity, my classes. All data is real; light / dark / mobile checked.
- Also: removed a stray CSS transform that kept the mobile sidebar open.
- Dashboard layout v2 (user: "one full-width unnecessary column, not well designed"): the long left column + thin right column is gone. Now equal-height rows:
  [Needs attention | Today | Coming up] → [role panel | role panel] → [Recent activity | My classes]; 3 → 2 → 1 columns on narrower screens; empty cards are centred.

## Class Messages without reloads
- pages/classes/messages.php now answers fetch calls with JSON (`ajax=open`, `ajax=poll`, POST `ajax=1`); the page itself renders from the same functions, so it still works without JavaScript.
- assets/js/messages.js: opening a conversation, sending (Enter sends, Shift+Enter new line), live updates every 6 s while the tab is visible ("Sent" turns to "Seen"), per-conversation drafts, auto-growing box, inline errors.
- The "Message a student" select is now a searchable picker (keyboard: arrows, Enter, Esc) with avatars; the list no longer clips it.
- Verified: HTTP (empty / bad CSRF / send / poll / student forced to own thread) and Chrome as teacher and student (no reload, no JS errors, dark, mobile 390 px). Test rows removed by marker.

## Attendance: Recent days
- Each day shows a date chip, Today/Yesterday/weekday, a present/late/official/absent bar, "x/y here · n absent", and a colour-coded rate (≥85 green, ≥70 amber, else red). The open day is highlighted, a dashed "Today – not taken" row appears when today is missing, up to 14 days with a link to the report; header shows days taken and class average. Side column widened to 300 px.

## Attendance: copy previous day, needs-attention panel, student warning
- "Copy <date>" button fills the sheet from the last marked day before the open date (students without a mark there stay unmarked); nothing is saved until Save.
- "Needs attention" card: students below 75% or with 3+ absences in a row, with a reminder bell (POST warn_student: teacher only, CSRF, only for at-risk students, once per day per student, new notification type attendance_warning, also e-mailed under the attendance category).
- Student attendance page shows a warning banner under 75%.

## Attendance: any past day, date-range reports, PDF for authorities
- Take-attendance page has a month calendar (prev/next, month picker, days coloured by attendance %, today/open day marked): any past day — months back — opens in one click and can be corrected or cleared. Rule unchanged: a student is on the sheet only for days on/after joining.
- Report view: From/To + presets (this/last month, 3/6 months, year, all); stats, table and CSV follow the period (getAttendanceSummary($class,$student,$from,$to), attendancePeriod()).
- pages/classes/attendance_print.php: standalone document with school logo/name/brand colour, class, teacher, period, totals, signature lines (teacher / head / stamp). Layouts: summary table, monthly register (P/L/A/O per day, one table per month, landscape), single-student day-by-day record (&student=). Students get only their own record. Opened via "PDF report", "Register (PDF)", row PDF icon and the student's "PDF" button; browser Save-as-PDF produces real text. Verified: A4 PDFs rendered (summary 1 p, register landscape).

## Attendance PDF v2: full words, one month per page
- Summary report: first page = whole period, then (periods over one month) one page per month with that month's per-student table. Columns now read Present / Late / Absent / Official present / Days recorded / Attendance.
- Register: one page per month, status written out in full in every cell (rotated, colour-filled), full-word totals, weekday under each date. "Compact register" (layout=compact) keeps the old P / L / A / O letter version, now also one month per page.
- Student record: day-by-day table, one page per month, "Not recorded" instead of a dash.

- Register cells now use ✓ (present) and ✗ (absent), L = late, O = official present, colour-filled; the whole class fits one landscape page. Compact register keeps P/L/A/O.

## Attendance stays in place (no reload)
- ajax-nav.js: any area marked `data-ajax-region` is refreshed on its own (links, GET/POST forms, confirm dialogs): no page jump, scroll and banner stay, URL updated with replaceState, `[data-ajax-sync]` elements (class overview strip) refreshed too. Attendance page wrapped in `#attWrap`; calendar, month picker, date box, recent days, Report tab, save, clear day and reminders all work in place. Other class pages can opt in the same way.
- Recent days is now compact: one line per day (weekday, date, bar, rate), 5 visible + "Show N more" (up to 14), calendar/report cover older days.
- Recent days: fixed-height scroll list (about 6 rows visible, up to 90 days), replaces Show more.

## Attendance: reasons, edit history, locking, no-class days (migration 033)
- Late / absent marks take a reason: Sick, Family matter, Travel, Transport / weather, Other (= excused) or Unexcused. Shown per student, counted as "of which excused" in the report, PDF summary and student record.
- Edit history: every change (new mark, status / reason / remark change, cleared day) is written to attendance_log; the sheet shows "Edit history" for the open day (when, who, student, old → new, remark change). Identical re-saves log nothing.
- Locking: school admin sets "lock days older than N" (0 = never); locked days are view-only for teachers (save / clear / no-class refused server-side, inputs disabled), the admin can still edit.
- No-class days (holiday, exam week): mark a day with a label; nobody is marked, it is excluded from every count, drawn struck-through in the calendar; refused when the day already has marks. Weekly days off (admin setting) are greyed in the calendar and pre-fill the label.
- Tested over HTTP with clean-up (saves, reasons, log count, no-class, locks, non-admin policy refusal). The admin "Attendance rules" form itself was not exercised (no admin login in the test jars).

## Class tabs: Insights v2, calendar
- Insights: 30-day attendance tile (links to the report) replaces the duplicate class average; new Grade spread (bucket bars), Attendance by day (last 14 days, click opens the day) and Top performers; "needs attention" rows have a Message button (opens that student's conversation); compact tiles, dark mode checked.
- Calendar shows no-class days from the Attendance tab for teachers and students.
- Sweep: all class tabs load for teacher and student with no PHP warnings.

## Grades PDF, People filters, Classwork badges
- pages/classes/grades_print.php (standalone, school branding, signature lines): Grade report (students: graded / missing / overall / rating, class average, grade spread, then a page with every work item), Gradebook (students × work, landscape, 6 items per page group, each group on its own page, cells tinted by band) and Student report card (every item: type, due, status, score, %, teacher feedback, attendance). Students can open only their own card; results not yet released stay hidden. Buttons on the Grades tab ("PDF report", "Gradebook (PDF)", per-student icon, student "Report card (PDF)").
- People tab: wider (1040 px), search by name / email and filter chips (Need attention, Missing work, Inactive 7+ days) in the browser with live count, Message button per student (assets/js/people.js).
- Classwork: "N to grade" badge on assignments (teacher), "Due soon" (under 48 h) for students.
- Checked: 390 px width has no page-level horizontal overflow on attendance, insights, people, grades, classwork; no PHP warnings on teacher / student tabs.

## PDFs without KPI tiles, grading scale, final marks, quiz reactivation (migration 034)
- Printed reports (attendance and grades) no longer have KPI / stat tiles: a plain one-line summary under the header instead.
- Settings › Grading scale (school admin): ratings, letters (A+ … F) and optional GPA; presets (ratings, A–F with GPA, A+ … F with plus/minus) or fully custom bands (name, from %, GPA points, colour), validated server-side (one band must start at 0, unique starts, ≤ 20 bands). Stored as JSON in app_settings 'grading_scale'; includes/grading.php (gradingScale, gradeBand, gradingShowGpa, gradingBasis). Used by the Grades tab (overall cell, legend, colours), grade PDFs, report card, Insights grade spread.
- Final marks: the gradebook rows now carry earned marks, marks of marked work, marks of all work, graded % and final % (earned ÷ all work, not marked / missing = 0). The "Graded 2/3" column (count of items marked) is replaced in the PDF by Marks earned / Out of (marked work) / Overall % / Final marks / Final % / Grade (+ GPA). "Grade is based on" (marked work or all work) is a setting; 'percent' everywhere follows it.
- Quiz reactivation (teacher, quiz page › Student Results): select students (all / not finished / any), pick a new deadline or none, confirm; the earlier attempt is cleared (old score kept in quiz_reopens, shown as ↻×n), the student can start again even after the due date until the new deadline, is notified, and the gradebook shows the quiz as pending (not missing). Scopes: selected, unfinished, everyone — all computed server-side from class members. Tested over HTTP (validation, scopes, notifications, retake and submit) and in Chrome (selection UI); test data removed.

## Final marks (sidebar Marks) — migration 035
- Each class has a marking scheme: Mid exam, Final exam, Activities, Quizzes, Assignments, each worth a number of marks (default 20 / 40 / 10 / 15 / 15 = 100; 0 = part not used), edited by the teacher on the Marks page (table class_marking_scheme). Exams get a kind (Mid / Final / Activity / Other) when created (exams.kind; existing titles with "mid" / "final" were classified).
- includes/final_marks.php: classFinalMarks() = part earned = (marks earned ÷ possible on the part's items) × weight; missed items (absent, not handed in past due, over time) count 0; students see only published exams and released results. Total, percent so far (parts with marks) and final percent (all parts); 'percent' follows the grading "based on" setting.
- Marks page: per class a "Final marks" table (students × parts, total, percent, grade — GPA only if the school turned it on, default off) with per-student statement PDF icon; students see their total, grade and a bar per part. pages/marks/print.php: class PDF and per-student statement (items behind each part), no KPI tiles.
- Tested with throwaway exams in class 30 (rendered class and student PDFs, student page); test exams removed.

## The class teacher decides the grading scale (migration 036)
- Each class has its own scale (class_grading_scales): Grades tab › "Grading scale" (also on the Marks page) — bands named by the teacher (A+, A, B …), where each starts, colour, GPA on/off (off by default), "grade is based on" marked work / all work, "also use for all my other classes", and "use the school default instead". The school administrator's page (Settings › Grading scale) is now only the default for classes without their own.
- includes/grading.php: gradingUseClass() sets the context (class_header, gradebook, final marks, PDFs do it), gradingScale() = class scale else school scale; shared editor gradingEditorHtml() + assets/js/grading-settings.js.
- Tested: validation, save, Grades tab labels / legend, student view, Marks pills, PDF text, reset, apply to all; test rows removed.

## Ranges: To %, marks range and GPA range per grade
- Every band shows its range: From % (set by the teacher), To % (worked out from the next band), "In marks" (of the class's total, e.g. 95–100 of 100) and GPA from / to. A student's GPA moves inside the grade's GPA range with the percentage (gradeGpa()), e.g. A+ 95–100% = GPA 3.7–4.0.
- Shown in the editor (live), the Grades tab legend, the Final marks chips on the Marks page, and the keys of the grade and final-marks PDFs. gradingValidate() rejects a highest GPA below the lowest; stored scales without gpa_max keep working (fixed GPA). New preset "Letters A–F with GPA ranges".

## Grade colours chosen by the teacher
- Every grade has its own colour (colour box + 12 one-click swatches) instead of four fixed tones; stored as `color` in the scale (old scales fall back to their tone's colour). gradingCss() writes per-band `--gc-bg` / `--gc-ink` classes (light and dark theme) used by the Grades tab cells and legend, Final marks pills, Insights spread, the editor preview and the grade / final-marks PDFs. Two classes with different scales on one page (Marks) do not clash (class id in the class name).

## Grade ranges written by the teacher, with greater / less than
- Each grade has From and To written by the teacher with a choice of ≥ or > for the start and ≤ or < for the end (A+ 90–100, A 80–89 … F < 50). Validation: no overlap, no gap bigger than 1, "exactly X%" must belong to a grade, top includes 100, bottom includes 0; empty "To %" is filled in. A mark between two written ranges (89.5) goes to the grade whose start it has reached. Range text (e.g. "> 90%", "50–80%", "< 50%") is shown in the editor preview, Grades legend, Marks chips and PDFs; grade names and ranges are HTML-escaped everywhere (a name like <b> is shown as text).

## Decimal marks and GPA kept
- "Decimal marks" rule per scale (class teacher / school default): round to nearest (86.5 → 87, default), down, up, or exact; it only decides which grade (and GPA inside the grade's range) a mark falls in — the mark itself is shown unchanged (gradingRound()).
- GPA from / to numbers are always visible and kept in the editor; the "Show GPA" switch only decides whether GPA appears in grades and reports (marks and grades only when off).

## One GPA per grade
- The "GPA from / to" pair is replaced by a single GPA column: each grade has one GPA, decided by its marks range (A+ 90–100 = 4, A 80–89 = 3.5 …). Old scales with a GPA range keep their lowest value. The "Show GPA" switch still decides whether it appears in grades and reports.
- Marks sheet (Final marks table) has its own GPA column next to Grade whenever "Show GPA" is on; the class PDF already had one.
- Marks sheet and final-marks PDF: columns Total, Percent, GPA, Grade (Grade is the last column). Marks page has a one-click "Show GPA / Hide GPA" button per class (toggle_gpa) — the GPA column was hidden for classes whose scale had "Show GPA" off.

## Round: school report, dashboard nudges, exam kind badge
- Reports › Whole school (super admin, pages/reports/school.php): every active class side by side (teacher, students, average grade, attendance, work handed in, students needing attention), sortable, with a PDF (school_print.php: header, one plain line, table, key, signatures — no KPI tiles). Totals only, no student names. "My classes / Whole school" tabs on Reports for the admin.
- Dashboard: teachers see "Classes without attendance today" (skips weekly days off and no-class days, links to the class when only one); students see "Classes where your attendance is below 75%".
- Exams list shows a "Mid exam / Final exam / Activity" badge for exams that count in the final marks.
- Stream: search and "All / Announcements / Classwork" filter in the browser (assets/js/stream-filter.js), shown when the feed has more than 5 items.
- Dashboard timetable: for teachers a lesson that has started links straight to that class's attendance ("take attendance").
- Security sweep of this session's new actions: as a student, toggle_gpa / save_scheme / warn_student / set_policy / reopen_quiz are refused, the class grading page and school report redirect away, report PDFs only ever show the student's own record; teacher POSTs without a CSRF token are refused.
- Notifications: filter by kind (Classwork, Grades, Messages & comments, Attendance, People & approvals) next to All / Unread; pagination keeps the filters.
- People: "Class list" PDF (roster) and "Paper sheet" PDF (blank monthly attendance sheet, 31 day boxes, landscape) — pages/classes/roster_print.php.
- Fixed: a school without its own scale showed ranges like "85–0%" (default scale was not validated); fresh-install schema verified in a throwaway database (all tables, new columns).
- Reports page: the teacher's class table gains Final marks (out of the class total), GPA (when on) and Grade; a student's "My report" card per class shows final marks and grade. Test accounts used for checks (@smartclass.test) removed.

## Student explanations for absences (migration 037)
- A student can explain an absent / late mark from the last 60 days (Attendance tab › "Explain this absence"): short text 3–255 chars, can be changed until the teacher answers. The class teachers are notified; the Attendance toolbar shows a count of pending explanations, and the open day has an "Explanations" card with Accept / Decline. Accepting marks the absence / late mark as excused (an existing reason is kept) and tells the student; declining tells the student. Answered explanations cannot be edited or re-decided; locked days cannot be decided by teachers. Tested over HTTP (validation, no CSRF, wrong day, student trying to decide, decline, accept, re-decide) with clean-up.

## Attendance self check-in (migration 038)
- Teacher (today's sheet, left column › "Self check-in"): open a 6-digit code for 5 / 10 / 15 / 30 minutes (shown large, with the time it ends and how many checked in), close it any time. Not possible on a no-class day or in a class without students.
- Student: while a code is open and they have no mark today, the Attendance tab shows "Check-in is open — type the code". The right code marks them Present with the remark "Self check-in" (never overwrites a mark the teacher made; written to the edit history; the teacher can still change it). Spaces in the code are ignored. 5 wrong codes in 10 minutes block further tries (attendance_checkin_tries). Only enrolled students; teachers cannot check in; CSRF on every POST.
- Tested over HTTP: closed, wrong code ×5 then blocked, right code, repeat, count, close, closed again; clean-up done.

## Demo records added to the empty modules (on request: "at least five records")
Backed up first (/tmp/scx/backups/before_seed_*.sql). Added, tied to the existing classes and students:
- Timetable (class_schedule): 14 lessons Mon–Thu + Sat for classes 30–37, no overlap for the teacher.
- Events (class_events): 6 upcoming events (parent–teacher meeting, science fair, museum trip, coding challenge, poetry day, lab-safety session).
- Forums: 6 threads with 11 replies (teacher answers and student follow-ups).
- Research: 6 real references (Einstein 1905, Watson & Crick 1953, RFC 791, RFC 9293, ECMAScript spec, Frankopan's Silk Roads).
- Resources: 5 files uploaded through the real upload form (3 PDFs, 2 text files), downloadable by students.
- Exams: 7 more exams (mid-terms for classes 30–34, a lab practical, an upcoming final) with 80 marks, results published for most; marking schemes for classes 30–34 (25 rows).
- Assignment comments (8), no-class days (6), attendance explanations (5: 2 accepted, 1 declined, 2 pending).
- Timetable slot titles no longer run under the delete button.

## Settings: every configuration on its own page
- Settings › Overview (pages/settings/index.php): grouped cards (Account, Teaching, School, System) with a one-line description each; only what the person may use (student: Profile, My AI; teacher: + Grading scale, Marking scheme; super admin: everything). The sidebar Settings menu lists the same pages, Overview first.
- New separate pages: Marking scheme (pages/settings/marking.php: choose a class, five weights with a live total, "apply to all my classes", reset to default, table of all classes), Attendance rules (pages/settings/attendance.php, admin: lock days + weekly days off as day chips), Grading scale hub (pages/settings/grading.php: every class of the teacher, who set the scale, GPA shown / hidden, Edit) and School default grading (pages/settings/grading_default.php, admin).
- Moved out of other pages: the marking-scheme editor (Marks page) and the attendance rules form (Attendance page) are replaced by a link to their Settings page. Grading per class is still edited from Settings › Grading scale › Edit (and the Grades / Marks pages keep their shortcuts).
- Tested with a throwaway admin: all 13 settings pages load, validation and saves work, non-admins are redirected, other teachers' classes cannot be changed; test account removed.

## Settings area, sticky header, header Export PDF
- Settings is ONE area with its own navigation (includes/settings_nav.php: Account — Profile, Password & security, Notifications, Appearance, My AI; Classes & grading — My classes, Grading scale, Marking scheme; School — School profile, Teachers, Attendance rules, Default grading, E-mail, Service terms; System — AI providers, System check, Backup). Every setting is its own page in the same frame (nav column + page, full width, collapses to a scrollable tab strip on phones). The old one-page Profile is split into Profile / Password & security / Notifications / Appearance; the class grading editor now lives under Settings › Grading scale; the sidebar has a single "Settings" link. Checked at 1366, 1000, 760 and 390 px: no horizontal overflow on any of the 17 settings pages.
- Sticky top bar fixed: `overflow-x: hidden` on <body> made it a scroll container so the header scrolled away; `overflow-x: clip` keeps the header fixed on desktop and phone (verified after scrolling 700 px on four pages).
- Header Export PDF: the part list now shows the real parts of the page (Class banner, Class numbers, Colour scale, Gradebook table, Final marks · class …) instead of "Section 1"; tool bars / tabs / settings menu are never offered or printed; settings pages export the page next to the menu; big table cards may run over several pages (no empty first page); the gradebook's score pills (buttons) now print as numbers; fixed a CSS class clash that shrank those pills.

## Academic years (migration 039) and records for the super-admin sections
- Academic years: every class belongs to a year (classes.year_id; academic_years). Settings › Academic years (super admin): the list of years and "Close <year> and start the next" — new year name / dates, choose classes to carry forward (each copied with its topics, lectures as drafts, assignments / quizzes without dates, resources, its grading scale and marking scheme, its teachers; optionally its students; new name allowed), then the old classes are archived. Archived classes stay readable (stream, grades, attendance, people, insights, calendar, reports and PDFs; banner "Closed year — read only") but every POST on them is refused (class_header.php) and nothing is published for them; they show under "Previous years" on My classes (teachers and students). New classes join the current year. Tested with a throwaway class (validation of name / dates / duplicates, copy with students, year switch, archive → read-only, POST refused) and fully reverted.
- Super-admin records (on request): 5 customers created through the real Customers form (own database, folders, config each) — Al-Noor High School, Riverside Academy (paused: payment overdue), Kabul Science Institute (limits 15 / 600 / 60), Green Valley School (AI and sign-ups off), Future Leaders College — with contacts and notes; their admin accounts use the temporary password in /tmp/scx/demo_customers.txt (must be changed at first sign-in). 5 teacher invitations through the Teachers page (4 pending, 1 cancelled). config/provider.php made world-readable so Apache can create customers on this machine.

## At-risk limits are settings (Settings › At-risk limits, super admin)
- Low attendance % (default 75), absences in a row (3) and low grade % (50) were hard-coded in ~20 places; they are now read from settings (attLow(), attStreak(), lowGradePct() in includes/classes.php) everywhere: Insights, People, student pages, Attendance, Reports (+ school report and PDFs), dashboards, reminders. Tested: changing 75 → 80 / 3 → 2 / 50 → 60 changed the flags on every page (Insights count 5 → 6, "Below 80%" tiles, student dashboard text) and the defaults came back when removed.
- Settings › Password & security also shows your 10 most recent sign-ins / sign-outs (time, IP) and when the password last changed, from the activity log.

## Import students from CSV
- People › Import CSV (pages/classes/import.php, teachers of the class): upload "name, e-mail" (header optional, comma or semicolon, ≤ 500 rows, ≤ 1 MB) → a preview that classifies every row (new account / existing student to add / already in class / bad / duplicate / teacher e-mail), nothing changes yet → confirm. New e-mails get an approved student account with a random password they never see (they sign in with the e-mail code or "Forgot password"); existing students are only added; the provider's student limit is respected; rows are re-checked right before writing; the preview expires after 30 minutes; CSRF on both steps. Tested on a throwaway class (all six row kinds, expired preview, missing CSRF, unreadable file) with clean-up.

## Excel export
- includes/xlsx.php: a small safe .xlsx writer (zip + XML; texts are always strings, never formulas). Excel buttons: Grades (gradebook + grading scale), Marks (final marks + grading scale), Attendance report (summary + day-by-day register, for the chosen period). Teachers of the class only; files verified by opening them with LibreOffice.

## Minutes late (migration 040)
- A "Late" mark can carry the minutes late (1–240, optional): a small box under the reason on the sheet, shown only for Late; stored only for late marks (a value sent for Present is ignored); in the edit history ("late (15 min)"), the student's own record ("Late · 15 min"), the attendance report ("Late min" column) and the Excel summary. Tested on a spare day with clean-up.

## Two-step sign-in (migration 041)
- Settings › Two-step sign-in (every user): authenticator-app codes (TOTP, RFC 6238 — Google / Microsoft Authenticator, Authy, 2FAS) on top of the password or the student's e-mail code. Setup: setup key (or otpauth link), confirm with a code, 8 one-time backup codes shown once; later: new backup codes (password), turn off (password + a current code). The secret is stored encrypted; a code works once (the accepted 30-second step is remembered); 5 wrong codes or 10 minutes end the attempt; nobody is signed in until the second step succeeds (hook in startUserSession(); the provider's logged support link bypasses it as before). Sign-in page: pages/auth/two_factor.php. Lost phone and codes: `php bin/reset_two_factor.php EMAIL [customer-domain]` on the server.
- Tested with a throwaway teacher: setup (wrong code, right code, secret encrypted), sign-in redirected to the second step, dashboard blocked until verified, wrong code, replayed code, backup code (once only), lockout after 5, disable (wrong password, then ok), plain sign-in again; account removed.

## Forum attachments (migration 042)
- A topic and each reply can carry one optional file (same type / content / size rules as every upload; random on-disk name in uploads/forum, never served directly). Shown as file chips under the post; downloads go through pages/forums/download.php for members and teachers of the topic's class only (others → sign-in / topics list). A bad file on a new topic is refused BEFORE the topic exists (no empty topic left behind). Deleting a topic or a reply removes its files from disk. Tested: .php refused, fake .pdf refused, upload, student download (attachment header, content), direct URL 403, reply with file, deletion cleanup (0 files left).

## Guardian alerts (migration 043)
- A student records an optional parent / guardian contact (name, e-mail, phone, "e-mail my guardian about absences") in Settings › Profile; the class teacher sees it on the student's progress page (phone and e-mail as links).
- The school administrator switches guardian alerts on in Settings › Attendance rules (default OFF; needs e-mail set up). Then, when a student is newly marked absent — and when a low-attendance reminder is sent — the guardian gets an e-mail (queued like every notification, category "guardian"; the same subject is never sent twice to one address within 12 hours). Every e-mail has a signed "Stop these e-mails" link (pages/email/guardian_stop.php, public, HMAC token, confirm button) that switches that student's guardian alerts off and cancels queued ones.
- Tested with a throwaway student / class and temporary e-mail settings (queue only — nothing was sent): validation, save, teacher sees contact, absent → one queued mail with class and date, re-save → no second mail, stop page (valid / invalid token), stop → no further mails; all test data and settings removed.

## Attendance print in Pashto / Dari
- The attendance report / register print page (pages/classes/attendance_print.php) has a language switch in its top bar: English, پښتو (Pashto), دری (Dari). Pashto and Dari render right-to-left (dir=rtl, Arabic-script fonts) with translated headings, column titles, status names, key and signature lines; dates are numeric (Y-m-d) and weekday names are translated. Works for the summary, register and compact layouts and for a single student's record; `&lang=ps|fa` in the link opens it directly.
- Tested by rendering the page as a class owner (read-only) in all three languages and checking screenshots; no PHP notices.

## Class list and blank attendance sheet in Pashto / Dari
- pages/classes/roster_print.php has the same English / پښتو / دری switch as the attendance report (right-to-left, translated headings, numeric dates; `&lang=ps|fa`). Tested by rendering the list and the blank sheet in all three languages (no notices) and checking a screenshot.

## Full-width pages and the student AI Tutor (migration 044)
- Fixed-width caps removed (profile, e-mail settings, lectures, people, quiz builder / taking, announcements, copy class): every page now fills the area beside the sidebar for teachers and students.
- **AI Tutor** (students only, sidebar "AI Tutor", also "Chat with the tutor" on every lecture): a full-height page with the student's saved conversations per class on the left, the chat in the middle and the class material on the right (pick one lecture or the whole class; open assignments listed). Sending, switching and deleting conversations happen without reloading. On tablets / phones the lecture picker becomes a drop-down.
- Rules: uses the existing AI rules (teacher's per-class "AI for students" switch, the student's own / teacher-shared / school key and their limits); the tutor explains and quizzes but refuses to write answers for open graded work; lecture text, earlier messages and the question are sent as data; answers are plain text shown with textContent; 60 questions per student per day; a conversation is only saved after the AI answered; every conversation is private to its student (checked on every request).
- Tested with a throwaway student / class / lecture and a local mock AI server: ask, follow-up (history sent), lecture text in the prompt, empty text, missing CSRF, someone else's class, someone else's conversation (read and delete), teacher switch off, delete; real-browser run (desktop dark mode and phone width, no horizontal overflow). All test data removed.

## Lecture fixes: picture handling, AI drawer, dark-mode markup
- **Pictures in the lecture editor (Preview):** drag the corner or side handles to resize with the mouse (keeps its shape, 10-100 %, a % badge shows while dragging); drag the picture itself (or its grip) anywhere — a marker shows where it will land and whether it is left, centre or right of the text (left / right = text flows around it). New pictures are inserted at 60 % instead of full width. The old buttons and size slider still work.
- **AI on the lecture page (students):** "Explain this code" works as before, inline under the code block. The right sidebar card is now a bigger "AI study chat" (wider column, tall chat box): Summarize / Key terms / Practice quiz and free questions (the AI Tutor chat of this lecture, saved in the student's tutor history) all answer inside the card; only the card's own box scrolls, nothing navigates and no overlay opens.
- **Markup:** text colours ({red} …) and the highlighter were flattened to plain text in dark mode — fixed; the editor toolbar is one tidy row.
- Tested with a throwaway teacher / student / class and a mock AI server, real mouse events (resize, move + align) and drawer actions in a real browser; test data and the uploaded test picture removed.

## Lecture code: Run, direct editing and output panels (no AI)
- **Run** (Python and JavaScript blocks, everyone who opens the lecture): runs the code in the reader's own browser and shows the real output under the block. Python uses Pyodide from cdn.jsdelivr.net (downloaded on the first Run, so it needs internet); JavaScript uses a worker. Each run happens in a throw-away sandboxed iframe (no cookies, no access to the site) and is stopped after 25 s (Python) / 6 s (JS); nothing runs on the server. input() cannot be answered here (a clear message is shown).
- **Direct editing:** every code block is editable in place — click in it and type (Tab indents, Ctrl+Enter runs). Changes stay in the page and are never saved; a Reset button appears once the code was changed. Copy and Run use the edited text.
- **Output panels:** write ```output … ``` (new "Output" button in the lecture editor toolbar) for the expected output, shown as a terminal panel. An untagged block right under an "Output:" line is shown as an output panel automatically, so older lectures (like "Loops in Python") get them without changes.
- Checked read-only on the real lecture 113 "Loops in Python" as its student (inside a rolled-back transaction).
- Untagged code blocks that are clearly a small Python or JavaScript program (print / import / def / assignments …) are recognised and get Run too and are labelled with the detected language; syntax outlines that only hold placeholders and comments stay plain.

## Grades, final marks and school report prints in Pashto / Dari
- The grade report / gradebook / report card (pages/classes/grades_print.php), the final marks statement (pages/marks/print.php) and the school report (pages/reports/school_print.php) have the same English / پښتو / دری switch as the attendance report (`&lang=ps|fa`): right-to-left, translated headings, columns, status names, part names, notes and signature lines, numeric dates, grade labels such as B+ kept left-to-right. One shared helper: includes/print_lang.php (pt(), pdate(), printLangBar(), printLangCss()).
- Tested by rendering each page read-only (inside a rolled-back transaction) in English, Pashto and Dari with no PHP notices, and by checking screenshots.
- Demo records added for super-admin sections that were nearly empty: four closed past academic years (2022–2023 … 2025–2026, so the list has five) and five e-mail log rows (four sent, one failed; recipients @example.invalid, marked "Demo record").
- **Admin side was empty:** the super admin account (users.is_admin = 1, id 3) owned no classes, so every module (Classes, Lectures, Assignments, Quizzes, Exams, Marks, Attendance, Events, Schedules, Research, Forums, Announcements, Resources, Students, Reports) showed nothing; all demo data belonged to the demo teacher. The admin is now a co-teacher of the eight demo classes that have the most content (ids 30–37: Mathematics, Physics, English, Chemistry, Biology, History, Computer Networks, JavaScript). Checked by rendering each module as the admin: 8 classes, 25 lectures, 15 assignments, 9 quizzes, 6 research items, 6 events, 6 forum topics, 18 announcements, 5 resources, marks / attendance / students lists all appear. To undo: delete the admin's co-teacher rows (class_teachers where teacher_id = 3 and role = 'co_teacher' on those classes).
- The account used for the super-admin side is admin@test.com (School Admin, id 113), not only the Gmail admin (id 3): it is now a co-teacher of the same eight demo classes (ids 30–37) and every module was checked as that account. The Exams page opens on "Upcoming" (2 of the 8 exams); the other 6 are under "Past".
- **Own classes for admin@test.com (replaces the shared co-teacher idea, so nothing the admin edits changes the demo teacher's classes):** `php config/seed_admin_demo.php` gives the school admin five classes of its own (copies of demo classes 30–34, names end with "· Admin", class codes ADMD…): topics, 15 lectures (published), 10 assignments with the students' 95 submissions, 5 quizzes, the same 58 students, 7 exams with marks, 4 events, 10 timetable slots, 13 announcements, 589 attendance marks, 4 forum topics with replies, 7 research items, marking scheme. `php config/seed_admin_demo.php --remove` deletes exactly those classes (codes ADMD…). The admin's earlier co-teacher rows on classes 30–37 were removed again (the Gmail admin, id 3, still has them). Resources: none — files cannot be copied from the command line because uploads/ belongs to the web server; upload them in the admin's Resources page.

## Super admin settings pass
- Checked every Settings page as admin@test.com (profile, password & security, two-step, notifications, appearance, My AI, my classes, grading scale, marking scheme, school profile, teachers, academic years, at-risk limits, attendance rules, default grading, e-mail, AI providers, system check, backup): no PHP errors or notices.
- Fixed: pages with a form plus side cards (E-mail, AI providers, My AI) were squeezed into a narrow column inside the settings frame (SMTP server / port / username unreadable) — the form now uses the full content width and the side cards (test e-mail, last 7 days, cron job …) sit below it; form rows use auto-fit columns; the settings menu no longer has its own inner scroll that hid the lower items (Default grading, E-mail, AI providers, System check, Backup) — the whole page scrolls.
- AI settings: the "Lecture study helper + practice quiz" feature is now named "Lecture study helper, AI Tutor + practice quiz" (the AI Tutor uses that provider setting).
- **Settings "reload" fixed (reproduced first in a real browser with real mouse clicks):** every click in the Settings menu swapped the whole page area, including the menu itself, and jumped back to the top — it felt like a reload (and my earlier "no inner scroll" change made the long menu scroll away with the page). Now a menu marked `data-ajax-frame` (ajax-nav.js, used by the Settings menu) swaps only the content (#settingsMain) and the page heading; the menu element stays in the page (only the highlight moves), the page keeps its scroll position, the menu is sticky again (compact so it fits), the address and Back / Forward still work. Verified over all 19 Settings pages: menu element untouched, no page reload, correct heading and highlight, Back works. Saving forms was checked on 8 pages: no reload, scroll position kept.

## Several attendance sessions per day (migration 045)
- Attendance can now be taken in up to 8 sessions (lessons / periods) on one day. On the Take attendance page a "Session of the day" card shows 1, 2, 3 … (a tick = already marked) and a + for the next one; each session has its own sheet, remarks, reasons, edit history and "Clear session". Self check-in marks the session it was opened for; "Copy previous day" copies the same session number. Every earlier mark became session 1, so nothing changes for classes that use one session.
- Reports: each session counts as one mark in the percentages and totals; Recent days shows "· 2 sessions"; the printed register, the CSV and the Excel file get one column per session (only for days that have more than one — "s1", "s2"); a student's own record lists each session ("· session 2"); the day-by-day record in the report card does the same.
- Tested end to end with a throwaway teacher / 3 students / class: save sessions 1 and 2 with different marks, switching sheets, pills, recent days, register print, summary totals, CSV, Excel, student view, check-in for session 3, clearing only session 2; all removed afterwards.

## Audit pass: permissions, accessibility, tidy-up
- **Permissions (tested over HTTP with a teacher, a second teacher, a member student, an outsider student, an admin):** admin-only pages (school, e-mail, AI, limits, attendance rules, years, system, backup, default grading, teachers, customers, school report) refuse teachers and students; teacher-only pages refuse students; another teacher's or an outsider's attempts to open class pages, prints, CSV / Excel exports, lectures, drafts, exams, forums, the image library, AI study / explain / tutor all fail without leaking class data; uploads by students and without CSRF are refused; lecture pictures are served to class members only (outsider 404, anonymous redirected). No holes found.
- **Fixed:** includes/ and docs/ were reachable by URL (includes/ai.php answered 200, functions.php gave a 500) — both folders now deny all web access; every page now sends X-Content-Type-Options, X-Frame-Options, Referrer-Policy and Permissions-Policy headers; the session cookie gets the Secure flag on https.
- **Accessibility (automatic audit of 19 pages as student / teacher / admin, light and dark):** text contrast raised to 4.5:1 (links, tabs, badges, required marks, danger red, notification badge, skip link) in both themes; visible keyboard focus for everything; reduced-motion respected; new assets/js/a11y.js keeps heading levels from skipping, names icon-only buttons, labels unlabeled fields and adds rel="noopener" to new-tab links; the AI Tutor send button and question box got names. White-on-gradient text (sidebar, class banners) is fine — the checker cannot see gradients.
- **Removed:** two empty stray files (s, t) in the project root, the leftover test picture in uploads/lectures (through the System check's "remove unreferenced files"), all audit / test accounts, classes and settings rows.
- bin/update_all.php: when the project's backups/ folder is not writable for the user who runs it (it belongs to the web server), the backups go to ~/smartclass-backups/updates/ instead; a maintenance page that cannot be switched on no longer stops the update. Run once: all 5 demo customer sites now have migrations 040–045.

## Expert review — everything on docs/EXPERT_TODO.md is done (2026-10-26)
- **Safety net:** restore drill (`bin/restore_drill.php`, passed again at the end: 66 tables, 122 foreign keys identical), staging copy, 84 automated tests on a throw-away database, monitoring in Settings › System check.
- **Production readiness:** force-HTTPS switch, rate limits, password rules, required admin two-step, cron heartbeat, indexes + list limits, independent secret key.
- **Privacy:** retention rules + nightly clean-up, "download my data", anonymise / delete a person, AI Tutor safeguarding, `docs/PRIVACY.md`.
- **AI:** monthly school budget with warnings, one-click "AI off for everyone", teachers can report a wrong AI answer, the Python Run button works from this server.
- **Product:** phone scan at 390 px, installable app (manifest + offline page), **Pashto and Dari interface** (header language dropdown, full right-to-left layout, ~1,900 phrases + sentence patterns + fragments, JavaScript pop-ups translated in the browser, checked page by page in screenshots at desktop, tablet and phone width), first-run guide for new teachers, question bank for quizzes, "only give more time" for a quiz, provider support access that the school can approve first, billing notes per customer.
- **Code health:** the "teaches this class", "attended" and attendance-percentage rules now live in one place each (`teachesClassSql`, `attendedSql`, `attendancePct`) with a test that pins them; `docs/ARCHITECTURE.md` explains the folders, the request, the security rules and how to add a screen / migration / test.
- **Migrations applied everywhere:** 044 … 052 (live system and the five demo customers).
- **Still for a person to do:** change the weak demo passwords (admin@test.com …), install the cron jobs (System check shows the exact lines), have a native Pashto and Dari speaker read the translations, WhatsApp sending is on hold.
