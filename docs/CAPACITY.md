# Live AI answers and many students — how many web-server workers

**Why it matters.** A normal page is finished in a fraction of a second. A live (streamed) AI answer is not: the PHP worker that serves it stays busy for the whole answer, usually 5–30 seconds (up to 300 s, the limit set in `includes/ai.php`). While busy, that worker serves nobody else. The person's session is released at the start of the answer (`session_write_close`), so their other pages still open.

**The rule of thumb.**

    workers needed ≈ (normal page requests per second × 0.2 s) + (AI answers started per second × average answer time)

Example: 30 students in one lesson all ask the tutor within the same minute, each answer taking about 15 s.
That is 30 answers × 15 s = 450 worker-seconds in 60 s, so about **8 workers** are busy on average and more at the peak, plus the ordinary pages. Under 16 workers this class would already feel slow for everybody.

These are estimates from that formula, not measurements of your server. Measure once with a real lesson (below) before relying on the numbers.

## What to set

Apache with PHP-FPM (recommended) — in the pool file (`pm.max_children`):

- 2 GB RAM server: `pm.max_children = 20` (about 60–80 MB per worker).
- 4 GB RAM server: `pm.max_children = 40`.
- Apache `mpm_event`: `MaxRequestWorkers` at least as large as `pm.max_children` plus 20, so waiting connections do not block.
- If PHP runs as an Apache module (`mpm_prefork`): `MaxRequestWorkers 40`; each worker is heavier, so one answer costs more memory.
- Put the AI endpoints behind the same limits you already have (`includes/ratelimit.php`): the per-person limit stops one student from holding many workers.

Reverse proxy (nginx in front): turn off buffering for the answer endpoints so words appear live: `proxy_buffering off;` (or `fastcgi_buffering off;`). The app already sends `X-Accel-Buffering: no`.

## How to measure on your own server

1. During a real lesson (or with 10 test accounts), run `systemctl status php8.x-fpm` (or `ps -C php-fpm8.x | wc -l`) to see how many workers are busy.
2. FPM status page (`pm.status_path = /fpm-status`) shows *active processes* and *max children reached*. If "max children reached" is above 0, raise `pm.max_children` (if memory allows) or ask students to ask the tutor in turns.
3. Write down: students at once, average answer time, busy workers at the peak. Use those three numbers in the formula.

## If it still gets slow

- Ask students to use the tutor in turns during a lesson, or spread the quiz / tutor activity over the period.
- Give the AI endpoints their own FPM pool with its own `pm.max_children`, so a busy tutor can never starve the normal pages.
- Move to a bigger server or add a second one only after "max children reached" stays above 0 with the memory limit already used.
