# SmartClass — Step-by-step TODO

Built from CLASS_IMPROVEMENTS.md, FEATURE_NOTES.md and AI_PLAN.md.
Each step is built, tested for teacher + student (and outsider), screenshotted,
then ticked here. Nothing existing is removed or hidden.

## Phase A — Class page

- [x] **A1** Messages tab: private-comment inbox for teachers, "Message teacher"
      for students, private comments on quizzes, unread counts (tab, class card)
- [x] **A2** Overview strip at the top of every class page (teacher / student)
- [x] **A3** "Due this week" section in Classwork
- [x] **A4** Edit stream posts
- [x] **A5** Calendar tab (due dates + attendance days, month view)
- [x] **A6** Classwork: mark lecture/resource as done + who opened it, search,
      collapse topics, attach a resource/link to an assignment
- [x] **A7** People: roster CSV, last activity + overall grade per student
- [x] **A8** Grades: grade by clicking a cell, colour scale, sort by column
- [x] **A9** Stream: links in posts, scheduled posts

## Phase B — More than one teacher

- [x] **B1** Invite teachers from the app (invite link, they set their password)
- [x] **B2** Co-teachers: several teachers per class (owner + co-teachers)

## Phase R — Roles, privacy and sign-in (decided 2026-10-20, before AI)

- [x] **R1** Super Admin (flag on users): admin area — invite teachers, teacher
      directory, Email + AI settings, backups; teachers can no longer invite teachers
- [x] **R2** Privacy audit: every teacher sees only their own classes, students,
      requests (Approve Students, View Students, Teachers page, dashboard, AJAX)
- [x] **R3** Co-teachers added by typing the colleague's email (no teacher list)
- [x] **R4** Join requests show the student's details (photo, email, signup date,
      verified, other classes); a pending student sees only the class name
- [x] **R5** Students sign in with a one-time email code (no password)
- [x] **R6** Sidebar: main modules as direct links

## Phase L — Lectures rebuild (client: "the current one is very poor")

- [x] **L1** Writing: formatting toolbar (headings, bold, italic, lists, quote, link,
      code), Write / Preview tabs, safe server-side renderer (old lectures still work)
- [x] **L2** Draft / scheduled / published — students see and are notified only on publish
- [x] **L3** Materials: class resources + several links / videos per lecture
- [x] **L4** Lecture page: contents from headings, reading time, previous / next,
      materials, "Ask the teacher about this lecture" (Messages), no duplicate title
- [x] **L5** Lecture list: search, class / topic filters, draft / scheduled badges
      (teachers), done / new + progress (students)
- [x] **L6** "Explain this code" (AI) on every code block, like DataCamp — step-by-step
      explanation under the block; follows the class AI switch / own / shared / school
      key rules; each explanation is cached and reused for the whole class
- [x] **L7** Text colours + highlighter (fixed safe palette) in the toolbar
- [x] **L8** Images in lectures: upload / drag-drop / paste, stored safely, shown only to
      the class; in the editor drag to move, align, resize, remove

## Phase C — AI (multi-provider)

- [x] **C1** AI Settings (super admin): OpenAI, Claude, Groq, Gemini, Mistral, DeepSeek,
      OpenRouter, Ollama, OpenAI-compatible; encrypted keys, model list,
      test connection, usage log + monthly limit
- [x] **C2** Generate quiz with AI → quiz builder
- [x] **C3** Assignment grading assistant + AI rubric
- [x] **C4** Release results + result emails (quizzes + assignments)
- [x] **C5** Explain my mistakes (students) + class insights summary (teachers)
- [x] **C6** Short-answer quiz questions with AI marking; lecture study helper
- [x] **C7** Remaining AI helpers (lecture/announcement/assignment writers,
      feedback rewriter, translation, reply suggestions, practice quiz, study plan)

## Phase D — Other

- [x] **D1** Forgot password (email reset link)
- [x] **D2** Backup: download / restore the database from Settings
- [x] **D3** Bulk actions (grade many, message all students with missing work)
- [x] **D4** Copy a class / reuse a post
- [x] **D5** Dark mode + mobile polish
- [x] **D6** Remaining sidebar modules (Announcements, Events, Schedules, Exams + Marks, Forums, Research, Reports)
- [x] **D7** WhatsApp notifications — DROPPED by the owner (2026-10-26); do not build

## Phase M — Hosting customers (done 2026-10-25, see docs/CUSTOMERS.md)

- [x] M1 One code, a database + login + storage per customer, chosen by domain; clean install (no demo data); first-run terms + new password
- [x] M2 School name / logo / colour (Settings › School)
- [x] M3 Customers page: list, pause / resume, service switches (AI, email, uploads, sign-ups), logged support access, create, delete
- [x] M4 bin/update_all, backup_all, migrate, create_customer, delete_customer
- [x] M5 per-customer limits (teachers / students / classes)
- [x] M6 school-wide report for the super admin (Reports › Whole school); exams count in the final marks (Phase F)
- [x] M7 customer approval of support access, billing notes (done — see docs/EXPERT_TODO.md 5.5)

## Phase F — Marks, grading, attendance, settings (done 2026-10-25/26, details in docs/IMPROVEMENT_PLAN.md)

- [x] F1 Messages tab without reloads (chat), searchable student picker
- [x] F2 Attendance: calendar to any past day, date-range reports, PDF summary / register / student record, reasons (excused), edit history, locking, no-class days, copy previous day, needs-attention panel, self check-in by code, student explanations
- [x] F3 Grades: PDF report / gradebook / report card, Final marks (mid, final, activities, quizzes, assignments with weights per class), Marks sheet with GPA column
- [x] F4 Grading scale decided by the class teacher: A+/A/B names, ≥ > ≤ < ranges, colours, one GPA per grade (optional), rounding; school default
- [x] F5 Quiz reactivation for chosen students (selected / not finished / everyone)
- [x] F6 Settings area: one navigation, every setting its own page (Profile, Password & security, Notifications, Appearance, My AI, My classes, Grading scale, Marking scheme, School profile, Teachers, Academic years, At-risk limits, Attendance rules, Default grading, E-mail, Service terms, AI providers, System check, Backup)
- [x] F7 Academic years: close a year, carry classes forward, archive the old as a read-only record
- [x] F8 Insights v2, school report, Stream search, notification filters, People filters, class list / paper sheet PDFs, CSV student import
- [x] F9 Excel export (grades, final marks, attendance), minutes late on attendance
- [x] F10 two-step sign-in (authenticator app), forum attachments
- [x] F11 guardian alerts (absence e-mails to a parent, stop link)
- [x] F12 attendance print in Pashto / Dari (right-to-left)
- [x] F13 class list & blank attendance sheet in Pashto / Dari
- [x] F14 full-width pages + student AI Tutor (chat per class, migration 044)
- [x] F15 grades, final marks and school report prints in Pashto / Dari (includes/print_lang.php)
- [x] F16 several attendance sessions per day (migration 045), audit pass (permissions, accessibility, headers)
- [ ] F17 (ideas) question bank, longer quiz deadline for one student
