# SmartClass — Security report

Scope: the application code in this repository, tested on a throw-away database. Not covered: the production server (TLS, PHP/Apache hardening, firewall, OS patches), third-party providers, social engineering. This is an internal review, not an independent penetration test.

## What was tested and the result

| Area | Test | Result |
| --- | --- | --- |
| Authentication — private pages | every page script requested as a visitor (RouteMatrixTest) | no private page opens without sign-in |
| Authorization | visitor / outsider / class student / class teacher / administrator on every page with real object ids (RouteMatrixTest) + `PermissionsTest` (8: admin-only pages, teacher-only pages, another teacher/outsider, member student, owner teacher, lecture pictures, student progress page) | no crash, no PHP error; permission tests pass. The matrix proves pages do not crash; whether every page shows the *right* content to each role is covered only by the `PermissionsTest` cases |
| SQL injection | 4 payloads (`' OR '1'='1`, stacked `DROP TABLE`, `UNION SELECT`, `SLEEP`) in 20 parameter names on all pages, as teacher and administrator (SecurityProbeTest, 1 152 requests) | no SQL error text, no 5xx, no slow response, `users` table intact. Code review: all queries with variables use prepared statements; the only interpolated identifiers (table/column names in `privacy.php`, `results.php`, `backup.php`, `customers.php`) come from fixed lists or validated slugs |
| Reflected XSS | `"><script>alert(7331)</script>` in the same parameters | never echoed unescaped |
| Stored XSS | lecture text (`SecurityTest`), rich-text rendering of announcements/exams/events (`AiWriteEverywhereTest`) | HTML and scripts are escaped; other user-text fields (forums, resources, research) were not individually tested for stored XSS — gap |
| CSRF | a forged POST without a token and with a wrong token to every page (SecurityProbeTest) | no row created, changed or deleted; static scan: every script that reads `$_POST` checks a token, except the three that must not (`email/unsubscribe.php` — secret token in the link, `whatsapp/webhook.php` — Meta signature `X-Hub-Signature-256` verified with HMAC, `results.php` helper) |
| Session / headers | cookie HttpOnly + SameSite, protection headers on every page, signed-in pages not cached, sign-in does not reveal whether the user exists (`SecurityTest`, `SecurityProbeTest`) | pass |
| Passwords | rules (length, common passwords, own name/e-mail, repeats), school-set minimum, weak-password refusal | `PasswordTest` (3) passes; reset page crash fixed (D-003) |
| Brute force | 8 wrong passwords lock the account, other accounts unaffected; success clears failures; counters/windows | `RateLimitTest` (4) passes |
| Two-factor | administrators must set it up when the school requires it (`HardeningTest`); reset tool exists (`bin/reset_two_factor.php`) | QR scan with a phone **not** verified |
| Uploads | 10 dangerous/disguised files (`.php`, PHP inside `.jpg`/`.txt`/`.pdf`, `evil.php.jpg`, `evil.jpg.php`, `.html`, `.exe`, empty, no extension) and a 16 MB file posted as a student's hand-in (`UploadSecurityTest`) | all refused, nothing stored, no database row. Not run on this machine: acceptance of a normal file and its random stored name — the test user cannot write to `uploads/submissions` (owned by the web-server user); the code path was read (`storeUpload`: random 32-hex name, extension from a whitelist, MIME checked with `finfo`, display name stripped of path/quotes). Downloads are sent with `Content-Security-Policy: default-src 'none'; sandbox` |
| Secrets | stored secrets use the key in `config/local.php`, independent of the database login; `bin/rotate_secrets.php` | `SecretsTest` (2) passes |
| Privacy | `PrivacyTest` (11 tests) | passes |
| WhatsApp webhook | Meta verify token (constant-time compare), body size limit 200 000, signature check before parsing | read in code; `WhatsAppTest` |
| Secret scan | tracked files and Git history | see S-001 |

## Findings

| ID | Severity | Summary | Status |
| --- | --- | --- | --- |
| S-001 | High | database password default in `config/db.php` and in the first commit | code fixed; history not rewritten; **rotate the password** |
| S-002 | Medium | CDN scripts without Subresource Integrity, floating `sweetalert2@11`, two jQuery versions | fixed: all libraries and fonts self-hosted; no site-wide CSP (would need an inline-script refactor) |
| S-003 | High | `.git`, `tests/` readable over the web on Apache; `setup.php` could wipe the database or create teachers without signing in | fixed (details and evidence in `defect-report.md`) |
| S-004 | High | `ajax/handler.php` signed users in without lockout or two-step verification | fixed |
| D-003 | High (availability) | password-reset page crashed | fixed |

## Residual risks
1. No site-wide Content-Security-Policy.
2. The automated tests run against PHP's built-in server, which ignores `.htaccess`. The web-exposure checks (S-003) were therefore made with `curl` against the local Apache and are listed in `test-results.md`; repeat them on the production server (the Installation Guide, section "Verify the installation", has the commands).
3. No automated scanner (OWASP ZAP, sqlmap) was run: not installed on this machine and not downloaded.
4. Rate limits and lock-outs were tested as unit/HTTP tests, not under sustained attack.
