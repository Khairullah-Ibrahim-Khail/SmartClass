# Final to-do list (what was left after the expert list) — ticked only when built AND tested

Items marked **YOU** need a person, an account or a decision outside the code; the list says exactly what.

## A. I do now
- [x] A1 Look at every chart / diagram kind in the browser: light + dark, English / Pashto / Dari, desktop + phone; fix what looks wrong.
- [x] A2 Translation review file: export every phrase, sentence piece and pattern (Pashto + Dari) to one CSV a native speaker can correct; import the corrections back without touching the source files.
      → done: bin/export_translations.php made docs/translations_review.csv (2,962 rows); bin/import_translations.php loads corrections into includes/lang/overrides.php; tested
- [x] A3 (built, not yet checked in a browser) Diagrams and charts for screen readers: a text version of every diagram (and a data table for every chart) next to it.
- [x] A4 Keep the big drawing libraries in the browser after the first use (slow mobile internet).
      → done: sw.js keeps the big libraries after first use, no background download; tested
- [x] A5 One command that checks everything (syntax of every PHP / JS file + the whole test suite) — `bin/check.sh` — and a pre-commit hook that runs it.
      → done: bin/check.sh (+ --quick) and .githooks/pre-commit; quick check passes
- [x] A6 HTML preview in lectures: stricter by default (no scripts or data from the internet inside the preview frame).
      → done: preview frame has no scripts or data from the internet; tested
- [x] A7 Guides in Pashto and Dari: teacher (first class, attendance, lectures with AI, charts, code) and student (join, attendance, AI tutor, quizzes).
- [x] A8 AI policy page for the school in plain words (who may use AI, what is sent, minors, parents) — a template the school can adopt.
- [x] A9 Git ready: `.gitignore` that keeps secrets, uploads and backups out, and a short setup note. (No commit is made for you.)
- [x] A10 Off-server backup copy: `bin/backup_offsite.php` copies the newest backup to a folder / SSH target you configure; tested with a local folder.
- [x] A11 Streaming and many students: measured guidance for the number of web-server workers, written down.
- [x] A12 Full test run + update docs.

## D. Attendance redesign (professional look) — added on request
Current problems: four small empty radio circles per student with no colour until clicked; four stacked side cards push the sheet down; small counts; plain rows (no photo, no warning); the Today list is a plain list. Everything that exists today stays (features, rules, Pashto / Dari + right-to-left, dark mode, phone / tablet, tests).
- [x] D1 Decide with the owner: table vs cards on desktop (recommended: segmented buttons in a table on desktop, cards on phone); photos or initials; reason for Late / Absent as a short list or free text.
- [x] D2 Sheet top bar: class, date with previous / next day arrows, session, coloured counts (present / late / absent / official / not marked).
- [x] D3 Student rows: photo or initials, name, ONE segmented control (Present / Late / Absent / Official) with big coloured buttons that work with the keyboard; a Late / Absent row opens a small reason + minutes field instead of always showing it.
- [x] D4 Helpers: Mark all present, Copy yesterday, a Save button that stays visible while scrolling; "not marked" rows highlighted; students below the attendance limit flagged on their row; Save still needs every student marked.
- [x] D5 Side tools (Self check-in, Not a class day, calendar, recent days, excuses) move into tabs / a panel that opens on request, so the sheet comes first.
- [x] D6 Hub: Today as cards (class, how many marked, one big "Take attendance" button), a small weekly strip, classes that need attention.
- [x] D7 Report: one clear table with a colour bar for each student's rate, filters, print / export buttons in one place.
- [x] D8 Checked with screenshots on desktop, tablet and phone in English, Pashto and Dari (light + dark); phone shows the sheet as cards.
- [x] D9 Tests: every existing attendance test still passes; new tests for the new controls (keyboard, reason field, save needs everyone marked).

## B. YOU (cannot be done from here)
- [ ] B1 Read and correct the Pashto / Dari translations (use the file from A2) — you said you are a native speaker.
- [ ] B2 Open the Java lecture ("what is java", class JAVA) on your real site and check the flowchart.
- [ ] B3 Run the new lecture charts once on your real site.
- [ ] B4 Try the live AI writing, the rate-limit messages and a Pashto / Dari question with your real AI provider.
- [x] B5 Install a code runner (Piston, one Docker command), enter its address in Settings › Code runner and press "Test the runner".
- [x] B6 (done 2026-10-26, running) Install the cron jobs (Settings › System check shows the exact lines).
- [ ] B7 HTTPS with a real certificate, real e-mail settings, change the demo passwords.
- [ ] B8 First commit and push to the private repo https://github.com/Khairullah-Ibrahim-Khail/SmartClass.git (branch main) — but first the `.gitignore` (A9) must keep config/local.php, config/instances/, instances/, uploads/, backups/, sessions/ and logs/ out; then choose where the off-server backups go.
- [ ] B9 One week pilot: one teacher, one real class; write down what is confusing.
- [ ] B10 Decide the AI rules for students / minors (A8 gives the draft) and tell parents.
- [ ] B11 One other person reviews the code runner, the HTML preview and the AI prompts for security.

- [x] B12 Phone notifications work on a real phone (confirmed by the owner 2026-10-27).

- [x] F1 Research is now a process (projects: proposal → decision → milestones → grade, with a timeline); the old library is the Library tab. Migration 055 applied to the real database and the five school databases (backups in ~/Documents).
- [x] F2 SSH off-server backup: `--port` / `--key` options, tested against a private SSH server.
- [x] F3 Safeguarding catches "hurting" words and the tutor's own crisis answers; 3 missed messages recorded.

- [x] F4 Pages and filters on the long lists (forums, announcements, events, resources, library, projects) and Edit screens for events, timetable slots, resources, library items and announcements.
- [x] B13 (done 2026-10-26, running) Install the three cron lines (scheduled posts, e-mail queue, daily clean-up) — see "How to schedule" in the last report: `sudo crontab -u www-data -e`.

## G. Research — next improvements (G1–G5 built 2026-10-26; migration 056 applied to the real database and the five school databases)
Now: build 1–5. Then wait for the pilot to show which of 6–8 teachers ask for.
- [x] G1 Deadline reminders: the student is told 2 days before a milestone is due and when it is overdue; the teacher is told about overdue milestones and projects with no activity for 14 days (nightly job).
- [x] G2 Milestone due dates on the Dashboard ("Coming up" card) and overdue ones in "Needs your attention".
- [x] G3 Milestone templates: one click adds a standard set (Literature review, Method, First draft, Final report); teachers can save their own.
- [x] G4 Sources: the student attaches Library items to a project; the project shows a source list.
- [x] G5 PDF project report (proposal, milestones, feedback history, grade) for the school's records — plain header and tables, no stat tiles.
- [ ] G6 Version history per milestone (Version 1, 2, 3 with the feedback each got).
- [ ] G7 Teacher-only notes and a second supervising teacher.
- [ ] G8 Rubric grading (weighted criteria give the grade) feeding Marks / final marks.
- [ ] G9 After the pilot, and only with the teacher in control: group projects; AI help (feedback draft for the teacher, "is my question clear?" for the student).

## C. Left alone on purpose
- Daily AI-written lectures from a roadmap (auto-publishing could show wrong content to students).
- The old attendance file in `pages/classes/` (the Attendance module loads it).

## E. WhatsApp (2026-10-27)
- [x] Level 1 buttons and Level 2 automatic sending, both fully configurable in Settings › WhatsApp (docs/WHATSAPP.md). Migration 057 applied to staging, live and the 5 school databases.
- [ ] B12 (yours): connect a real WhatsApp Business account, get templates approved, set the webhook, press "Send me a test" — only then is real delivery proven.

## F. Assignments rebuilt (2026-10-27)
- [x] Grading screen, "return for changes" and hand in again, clear teacher and student pages and lists (docs/ARCHITECTURE.md › Assignments). Migration 060 applied to staging, live and the 5 school databases.
- [ ] Not done yet (honest list): reusable feedback snippets (comment bank); AI filling the rubric lines one by one (it fills the total and the feedback today); a late-work penalty; keeping every earlier version of a student's file (only the latest is kept, with a version number); the quiz side of the new screens.
