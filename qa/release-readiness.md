# SmartClass — Release readiness

Version: working tree of 2026-10-27 (migrations 001–062). Decision basis: `test-results.md`, `defect-report.md`, `security-report.md`.

## Acceptance criteria

| # | Criterion | Result | Evidence |
| --- | --- | --- | --- |
| 1 | `sh bin/check.sh`: no syntax error, all tests pass | **Met** — 169/169 | `test-results.md` §1 |
| 2 | Route matrix and security probe: zero findings, zero new PHP warnings in the error log | **Met** | §2 |
| 3 | Clean install gives 76 tables, all migrations recorded, working first-administrator sign-in | **Met** | §3 |
| 4 | Restore drill: identical rows, checksums, foreign keys | **Met** | §3 |
| 5 | Key pages median < 0.5 s, none > 2 s on the reference machine | **Met** (all < 30 ms) — single user only | §4 |
| 6 | No open defect of severity High or Critical | **Met in code; one action needed outside the code** — S-001 (rotate the database password; history still contains it) | `defect-report.md` |
| 7 | No secret in tracked files | **Met** for the working tree; **not met for Git history** (S-001) | scan |

## Decision

**Conditionally ready for a controlled pilot** (one school, administrators watching), **not yet ready for open release**, because:

Must be done before real students use it (owner actions):
1. Change the database password on every server that ever used the old one and update `config/local.php`; decide whether to rewrite repository history (S-001). Treat the old password as public if the repository was shared.
2. Delete `setup.php` on every deployed copy and confirm the web-exposure commands in the Installation Guide return 403/404 on the real server (S-003 was found on the local Apache; the production server may differ).
3. Serve over HTTPS and switch on the HTTPS rule.
4. Take a backup, copy it off the server, run the restore drill.

Recommended before wider release:
5. Optional hardening: a site-wide Content-Security-Policy (needs the inline scripts moved out first). S-002 (CDNs) is done: everything is self-hosted.
6. Test on Firefox/Safari/Edge and on phones; review Pashto and Dari screens visually.
7. Load test on the real web stack (Apache + PHP-FPM) with the number of simultaneous users expected in a lesson, including AI streaming.
8. Real WhatsApp (Meta account), real e-mail and a real AI provider have only been tested against fakes.
9. Phone scan of the two-step QR code was not verified.
10. A screen-reader / keyboard-only walkthrough.

## Defects: found / fixed / remaining
Found 13 items (D-001…D-005, S-001…S-004, H-001 proxy HTTPS, H-002 base path, Q-001, Q-002) + 1 informational (N-001). Fixed 12 (everything except the owner-side part of S-001). Remaining: S-001 password rotation / history (owner action), N-001 (informational).

## Known limits of this assessment
Single machine; PHP built-in server for the automated tests; one browser; no external penetration test; no real third-party services; manuals' screenshots come from a demonstration database, not from a production school.
