# Hosting customers (multi-customer setup)

One code folder serves your own system and any number of customers. **Every customer has their own database, their own
database login, their own uploads / backups / sessions folder and their own admin.** The code never leaves your server.

## How a request finds its customer
`config/db.php` looks at the domain of the request. If `config/instances/<domain>.php` exists, that file gives the customer's
database login, folder and settings. Any other domain is your **original system** (`SC_IS_MASTER`). Sessions, cookies and
uploads are per customer, and a session made for one customer is refused by another.

Restrict the original system to your own domains: add `'master_hosts' => ['yourdomain.com', 'localhost']` to
`config/provider.php`; every other unknown domain then gets "Unknown site".

## Create a customer
```
php bin/create_customer.php --name="Friend School" --domain=friend.example.com --admin-email=owner@friend.example.com [--admin-name="Owner"]
```
(or the form on **Customers** in the sidebar). It creates the database + a login that can only open that database, imports a clean
schema (no demo data), creates the first admin with a **temporary password shown once**, makes `instances/<slug>/`, writes the
config file and adds the customer to the Customers page. Then:

1. Point the domain at the server and add a virtual host with `DocumentRoot` = this folder (the same folder for every customer);
   `sudo certbot --apache -d friend.example.com`.
2. `sudo chown -R www-data:www-data instances/<slug>` (run `bin/*` as the web user, `sudo -u www-data php bin/...`, so file owners match).
3. Send the admin the address, email and temporary password. At the first sign-in they accept the service terms and choose their own password.

## What the customer controls
Everything inside their copy: school name / logo / colour, classes, teachers, students, email account, AI keys, backups.
They cannot reach another customer or your system (separate database login), and cannot change the provider switches.

## What you control (Customers page, original system only)
- **Pause / resume** — the customer's site shows "This service is paused" (their admin can still sign in and **download a backup**; restore is blocked).
- **Service switches** — AI, email, uploads, new sign-ups. Stored in *your* database; the customer's copy asks for them (cached 60 s; if your
  database cannot be reached it keeps the last answer, so a hiccup never stops a school).
- **Support access** — a one-time signed link (opens within 10 minutes, works once) that signs you in to their admin side for 60 minutes. It needs a reason,
  shows a banner, is written to their activity log (and notifies their admin) and to your Customers log.
- **Limits** — maximum teachers, students and classes per customer (empty = no limit). Enforced where accounts / classes are created: sign-up of a new student, accepting a
  teacher invite (open invites count), creating or copying a class; the customer sees "This installation has reached its limit of N … contact your provider".
- **Numbers** — teachers / students / classes, last activity, terms accepted, disk use. Counts only, never content.
- **Delete** — a final backup is written to `instances/_archive/`, then database, login, config and files are removed.

## Updates, backups
- Change the code once → every customer has it. Then run `php bin/update_all.php`: for each database it shows a maintenance page, takes a backup,
  applies the new `config/migrations/*.sql`, and brings the site back; a failing customer is reported (with its backup) and the others go on.
  Write migrations so that running them twice is harmless (`CREATE TABLE IF NOT EXISTS`, …) — DDL cannot be rolled back.
  `php bin/update_all.php --dry` shows what would run. `php bin/migrate.php [domain]` does one database.
- `php bin/backup_all.php` backs up everything into `backups/all/<date>/` (keeps 14 days) — put it in cron.
- After you add a table to `config/smartclass_db.sql`, run `php bin/build_install_schema.php` so new customers get it.

## Terms
The text customers accept is in `includes/terms.php` (bump `TERMS_VERSION` to ask again). Customers are responsible for their data and their students'
permissions; you host it as-is, may pause it or switch provider services, and may open the admin side only for support (always recorded).
Have a lawyer read it once before the first paying customer.

## Local testing
`php -S 127.0.0.1:8101 -t .` and request with a customer's domain in the `Host` header (`curl -H "Host: friend.test:8101" …`), or map `*.test` to 127.0.0.1
(`chromium --host-resolver-rules="MAP *.test 127.0.0.1"`). On this machine run `sh bin/fix_local_apache.sh` once (Apache is read-only under /home otherwise); it also lets the Customers page create / delete customers
(`config/instances` and `instances` must be writable by the web server — the page says so if they are not). Deleting a customer leaves a final backup in `instances/_archive/` (remove it yourself when you no longer need it).
