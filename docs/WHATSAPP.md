# WhatsApp

Everything is off until the administrator switches it on (Settings › WhatsApp › School rules). Everything below can be changed there at any time — switches, times, limits, stop words, and the wording of every message in English, Pashto and Dari.

## Level 1 — buttons (no account needed)
A WhatsApp icon appears next to an absent student (attendance sheet), in "Needs attention", and on announcements ("Share"). It opens WhatsApp with the message already written; the person presses Send. It appears only if Level 1 is on and the person saved their number and agreed (Settings › WhatsApp › My WhatsApp).

## Level 2 — automatic sending (official WhatsApp Business Cloud API)
1. In Meta (business.facebook.com / developers.facebook.com): create a WhatsApp Business account, add a number, make a permanent access token. Note the **Phone number ID**.
2. Settings › WhatsApp: paste Phone number ID + token into "The school's sender" (administrator) or "My own sender" (a teacher; only if the administrator allows it). Tick which messages it sends and switch it on. Tokens are stored encrypted and shown only as the last 4 characters.
3. Messages: each message and language is shown with its Meta form (`{{1}}`, `{{2}}`…). Create a template in Meta with exactly that text, wait for approval, and type its name in the "template name" box. A language without its own template name uses the English template.
4. School rules: switch on "Level 2: automatic sending". "Send me a test" proves the sender works.
5. Webhook (so STOP works): Meta app → Webhooks → WhatsApp, callback URL = the address shown on the page (must be https), verify token = the one on the page, subscribe to `messages`; paste the app secret into School rules.

## Student numbers
While WhatsApp is on and "Students must give a WhatsApp number" is ticked (School rules), registration needs a valid number: the student's own, or a father's, uncle's or elder brother's (the student says whose). Giving it is the school's rule for registering, so there is no separate tick; the person can still stop messages by replying STOP. Several brothers may share one number. Students already registered see a banner until they add one (Settings › WhatsApp), and the class teacher can type it on the student's page. A student cannot clear the number while it is required. Nothing in the system punishes a student: the only effect is that registration cannot be completed without a number.

## Messages and limits
Messages are created only when something happens (marked absent, result announced, one "due tomorrow" summary a day). There is no daily cap by default; a sender may have an optional cap (0 = none) as protection against a mistake. Meta has its own limit on how many different people one business account may start chats with per 24 hours (about 250 at first, 1,000 and more as the account grows and keeps good quality) — check Meta's current rules.

## Rules the system keeps
Nothing is sent to a number that has no recorded agreement or that replied STOP; replying STOP (words editable) withdraws it and cancels what waits. Quiet hours (default 21:00–07:00) delay messages; a sender may have an optional daily cap; failures are retried (default 3 times, 15 min apart) and then shown with the reason in "Recent messages". The same message is never queued twice. A teacher's own sender is used for their classes, otherwise the school's.

Cron: `cron/send_emails.php` (every 5 minutes) builds the "due tomorrow" and teacher "today" summaries and sends the queue.

Tests: `tests/WhatsAppTest.php` uses a fake Cloud API; delivery through the real Meta can only be proven once a real account is connected.
