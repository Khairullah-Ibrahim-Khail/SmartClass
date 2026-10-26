# Privacy and personal data — how SmartClass handles it

This page is for the school administrator (and for the school's own privacy notice). It describes what the system really does — each point
below is enforced in code and covered by `php tests/run.php`.

## What is stored about people
| Data | Where | Who can read it |
|---|---|---|
| Name, e-mail, photo, role, last sign-in | `users` | the person; teachers of the person's classes (name, e-mail); administrators |
| Parent / guardian name, e-mail, phone | `users.guardian_*` | the student; **teachers of that student's class** (progress page); the guardian e-mail is used only for absence alerts and carries a stop link |
| Marks, submissions, quiz attempts, attendance | class tables | the student (own, released results only); teachers of the class; administrators |
| Messages with teachers | `private_messages` | the student and the teachers of that class |
| AI Tutor conversations | `ai_tutor_chats`, `ai_tutor_messages` | **only the student**. Teachers and administrators cannot read them. The one exception: a message that suggests the student is in danger is flagged to the class teachers (see Safeguarding) — only its first 300 characters |
| Who did what (sign-ins, edits) | `activity_log` | the person sees their own recent sign-ins; administrators |
| Sign-in codes, password-reset links, two-step secrets | own tables | nobody (hashed or encrypted) |
| Saved passwords | `users.password` | nobody — one-way hashes |
| E-mail / AI provider keys | `app_settings`, `ai_providers` | nobody — encrypted (`config/local.php` secret key); shown only as the last 4 characters |

Teachers of **other** classes, other students and anonymous visitors cannot read any of the above (tests: `PermissionsTest`, `TutorTest`).

## What leaves the system
* **E-mail**: notifications and absence alerts go through the mail server set in Settings › E-mail. Nothing is sent while e-mail is off or on a staging copy.
* **AI providers** (only when AI is on): the text of the lecture, the class name and the student's question are sent to the provider chosen in Settings › AI providers
  (or the student's / teacher's own provider). Names, e-mails and marks are **not** sent. Choose a provider you trust with school content; a self-hosted one (Ollama) keeps everything inside the school.
* **Phone notifications** (only when a person turns them on in Settings › Notifications): the browser's push service (Google, Mozilla, Apple or Microsoft) learns the device's push address and that SmartClass rang it — an empty signal, never any text. The phone then asks SmartClass for the text with the person's own sign-in. A signed-out phone only shows "You have a new notification". The school can switch them off for everyone; a staging copy never sends them.
* **Code "Run" button**: Python is downloaded from a public CDN (`cdn.jsdelivr.net`) and runs in the student's browser; no code is sent anywhere.
* Backups contain **everything**, including password hashes. Store them like the database itself.

## How long data is kept (Settings › Privacy & data)
Cleaned every night by `cron/housekeeping.php` (set the number of months, 0 = keep): activity log (12), AI usage (12), AI Tutor conversations (12 after the last message),
read notifications (6), sent e-mail copies (3), attendance edit history (24). Short-lived data (sign-in codes, reset links, rate-limit counters) is always removed within days.
Marks, attendance, submissions, lectures and messages are **school records**: no rule removes them.

## A person's rights
* **Access / copy**: Settings › Profile › *Download my data* (a JSON file) — and, for the administrator, Settings › Privacy & data › *Download their data*.
* **Removal**: Settings › Profile › *Ask the school to remove my data* tells the administrators. They use Settings › Privacy & data:
  * **Anonymise** (recommended): keeps the school's records but the name, e-mail, photo, parent contact, sign-in data, notifications, AI conversations and activity are removed; the account is closed.
  * **Delete everything**: removes the account and all that depends on it (students, and teachers without classes).
  Administrators cannot be removed this way. Each action needs the person's e-mail typed as confirmation and is written to the activity log (without personal details).

## Safeguarding (AI Tutor)
A student's message that suggests self-harm, abuse, violence or bullying is **not sent to the AI**. The student receives a fixed, kind answer that points to a trusted adult; the class teachers and administrators
get a notification and see the flag under *Safeguarding* with the start of the message. The detector is a short word list (English, plus a few Pashto / Dari phrases) — it is a safety net, **not** a replacement for teachers who know their students.
It can miss messages and can flag harmless ones. Switch: Settings › Privacy & data. Your school's child-protection procedure decides what happens next.

## Security measures you can rely on
Passwords are hashed; sign-in has rate limits and optional two-step codes (required for administrators if the school switches it on); every form has CSRF protection; uploads are checked by content;
pages send protective headers; the web server cannot list `includes/`, `config/`, `docs/`, `backups/`; secrets are encrypted with a key outside the database login.
See `docs/EXPERT_TODO.md` for the full list of what was added and tested.

## What the school still has to do
Tell students and parents what is stored and why (this page can be the base), keep the server and backups physically and digitally safe, remove staff accounts when people leave, and follow the laws that apply where the school is.
