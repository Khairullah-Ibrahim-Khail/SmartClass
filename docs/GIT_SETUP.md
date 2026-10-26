# Putting SmartClass in git (private repository)

This only prepares things. Nothing has been committed or pushed for you.

`.gitignore` already keeps these out of the repository, so a private repo still never holds them:
database login and secret key (`config/local.php`), every hosted school's settings (`config/instances/`, `instances/`),
uploaded files, backups, sessions and logs. The `.htaccess` files inside those folders stay in, because they protect the folders.

First time, from the project folder:

```
git init
git add .
git status          # look at the list once: no config/local.php, no uploads, no backups
git commit -m "First version of SmartClass"
git branch -M main
git remote add origin https://github.com/Khairullah-Ibrahim-Khail/SmartClass.git
git push -u origin main
```

Optional: run the quick syntax check before every commit

```
git config core.hooksPath .githooks
```

On the live server, `config/local.php` is created by `php bin/init_local_config.php` (or copy your own); it is never part of git.
