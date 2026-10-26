# SmartClass — AI Plan (multi-provider)

Agreed direction (2026-10-20): **AI drafts, the teacher decides.** Nothing
AI-generated is published, graded or emailed without a teacher's approval
(except grading modes the teacher explicitly turns on for practice work).

---

## 1. AI providers (switchable in the app)

One **AI Settings** page (teacher). Pick a provider, paste the API key, pick the
model — no code changes to switch.

| Provider | Notes |
|---|---|
| **OpenAI** | GPT models |
| **Anthropic Claude** | Claude models |
| **Groq** | very fast, low cost (open models) |
| **Google Gemini** | |
| **Mistral** | |
| **DeepSeek** | low cost |
| **OpenRouter** | one key, many models |
| **Ollama (local / self-hosted)** | free, private, runs on your own server |
| **Any OpenAI-compatible API** | custom base URL (Together, Fireworks, LM Studio, …) |

Design:
- One adapter per provider behind one helper (`includes/ai.php`): `aiChat()` returns
  text / validated JSON, with timeout, retry and clear error messages.
- **Model list loaded from the provider** (its models endpoint) where available,
  or typed in — no hard-coded model names that go out of date.
- **Per-feature provider choice** (e.g. Groq for quick quiz drafts, Claude/OpenAI
  for grading) + a **fallback provider** if the main one fails.
- Keys stored **encrypted** (same as the SMTP password), never shown again.
- **"Test connection"** button (like "Send test email").
- **Usage log**: feature, provider, model, tokens, cost estimate, teacher, time;
  **monthly limit** per teacher and for the whole school; AI turns off at the limit.
- Off by default; without a key every feature works manually as today.

---

## 2. AI features — teachers

| # | Feature | Where |
|---|---|---|
| T1 | **Generate quiz** from lectures / resource text / a topic: count, difficulty, marks, question types → fills the quiz builder for review | Create Quiz |
| T2 | **Question bank**: save good questions, reuse them, AI generates variants | Quizzes |
| T3 | **Grading assistant for assignments**: rubric → suggested marks per criterion + feedback → Accept / Edit / Reject / Approve all | Assignment grading |
| T4 | **AI rubric builder** from the assignment instructions | Create Assignment |
| T5 | **Short-answer quiz questions** marked by AI against the teacher's model answer | Quizzes |
| T6 | **Lecture assistant**: outline, improve text, add examples, summary, key points | Add Lecture |
| T7 | **Announcement writer** (clear, polite, short) | Class Stream |
| T8 | **Assignment writer**: instructions + rubric from a short idea | Create Assignment |
| T9 | **Class insights summary** in plain language: weak topics, students at risk, what to re-teach | Class › Insights |
| T10 | **Feedback rewriter**: turn short notes into kind, useful feedback | Grading |
| T11 | **Translate** lectures / instructions (e.g. English ↔ Pashto / Dari) | Lectures, Assignments |
| T12 | **Reply suggestions** for private comments | Messages |

## 3. AI features — students

| # | Feature | Where |
|---|---|---|
| S1 | **Explain my mistakes** after a quiz (why the right answer is right) | Quiz review |
| S2 | **Lecture study helper**: summary, key points, flash cards, "ask about this lecture" (answers only from that lecture) | Lecture page |
| S3 | **Practice quiz** generated from a lecture (not graded) | Lecture page |
| S4 | **Feedback explained**: what to improve next time, based on the teacher's grade + feedback | Assignment result |
| S5 | **Study plan** from upcoming due dates and weak topics | Dashboard |

Students never get AI help to *do* graded work for them (no "write my
assignment"); the helper is limited to explaining and practising.

---

## 4. Checking + results flow (quizzes and assignments)

- **Grading mode per quiz / assignment**: Teacher only · **AI suggests, teacher
  approves (default)** · AI grades automatically, teacher can override (practice only).
- Every grade records how it was made (teacher / AI accepted / AI edited).
- **Release results** button: results reach students when the teacher releases
  them (all at once), not one by one.
- On release, a **result email** per student: score, feedback, per-criterion
  breakdown, questions missed + explanations, link to the full result (uses the
  existing email system and each student's email preferences).

---

## 5. Safety, privacy, cost

- **Prompt injection**: student answers are passed strictly as data, outputs are
  forced into a fixed JSON format and validated, suspicious answers are flagged,
  a teacher approves grades.
- **Privacy**: only content is sent (lectures, questions, answers) — never names,
  emails or IDs. Ollama option keeps everything on your own server.
- **Cost**: usage log + monthly limits + caching of repeated requests.
- **Reliability**: timeouts, retries, fallback provider, manual flow if AI fails.
- **File answers**: typed answers first; PDF / Word text extraction is a later step.

---

## 6. Other features still to build (non-AI)

**From the current plan**
- Multi-teacher: invite teachers from the app, **co-teachers** in one class
- **Private-comment inbox** (Messages tab) + "Message teacher" for students
- Class **overview strip**, **Due this week**, **Calendar** tab
- Edit stream posts, attach links/files to posts, scheduled posts
- Lecture "mark as done" + who opened each lecture
- Attach resources/links to assignments
- Grade from the gradebook (click a cell), sorting, colour scale, roster CSV
- **Backup** (download / restore the database from Settings)
- WhatsApp notifications (optional)

**Modules in the sidebar not built yet**
- Exams, Marks, Research, Schedules (timetable), Forums, Events, Announcements
  (school-wide), Reports

**Product polish**
- Forgot password (email reset link)
- Dark mode, mobile polish
- Bulk actions (grade many, message all students with missing work)
- Copy a class / reuse posts from another class
- Parent / guardian read-only view (optional)

---

## 7. Build order

1. AI Settings: providers, encrypted keys, model list, test connection, usage log + limits
2. T1 Generate quiz → quiz builder
3. T3 + T4 Grading assistant + rubric (assignments)
4. Release results + result emails (quizzes + assignments)
5. S1 Explain my mistakes, T9 Class insights summary
6. T5 Short-answer AI marking, S2/S3 Lecture study helper + practice quiz
7. The remaining T/S features
