# SmartClass — Testing Checklist (finished modules)

Agreed to keep (2026-10-20). Everything below was built and tested by script
over HTTP; these are the checks that need a person, a real browser, a real mail
account or the real server.

---

## 1. Checks that could not be fully tested automatically

- [ ] **Real browsers** (Chrome, Firefox, a phone): especially the quiz timer,
      drag-and-drop upload, dropdowns and SweetAlert dialogs.
- [ ] **File uploads on Apache** — first apply the one-time
      `sudo systemctl edit apache2` setting (README › Local Apache), then try
      resource upload / download, assignment files and profile photos.
- [ ] **Email with a real account** (cPanel or Gmail): Settings › Email Settings
      › **Send test email**, then an announcement reaching a student's inbox
      (also check the spam folder).
- [ ] **Deployment to cPanel**: edit only the config block in `config/db.php`,
      run `setup.php`, add the two cron jobs, delete `setup.php`.

---

## 2. Workflows — try as a teacher and as a student

- [ ] Register with a class code → teacher approves on **People** → student sees
      the class.
- [ ] Teacher posts a lecture, an assignment and a quiz → student is notified →
      submits / takes the quiz → teacher grades → student sees the grade and
      feedback.
- [ ] Take attendance (try saving with a student unmarked) → the report → the
      student's own view.
- [ ] Archive a class → the student loses access → restore it.
- [ ] Change your password, profile photo and email preferences.

- [ ] Class page (Phase A): send a private message both ways (Messages tab badge),
      edit a stream post, schedule a post for a few minutes ahead and check it
      appears + notifies, mark a lecture done as a student and see it under
      "See who opened it", grade a cell in Grades, download the roster CSV,
      attach a resource + link to an assignment, browse the Calendar month.

---

## 3. Things to check everywhere

- [ ] Long names, Pashto text and apostrophes (`O'Brien`) display correctly.
- [ ] The site on a phone.

---

**Demo logins:** `teacher@test.com` / `student@test.com` (password `password`),
or any `@smartclass.demo` account (password `password`). Remove demo data with
`php config/seed_demo.php --remove`.
