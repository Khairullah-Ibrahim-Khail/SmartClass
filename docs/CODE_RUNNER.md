# Code runner (Run button for C, C++, C#, Java, PHP, Go, Rust, Ruby, Kotlin, Swift, Bash, SQL, Lua, Perl, TypeScript)

Python, JavaScript, HTML and CSS run in the reader's own browser. Every other language runs on a **Piston** server (an open-source code runner in a Docker container).

## What is installed on this computer (2026-10-26)
- Container `piston_api` (image `ghcr.io/engineer-man/piston`), listening only on `127.0.0.1:2000`, restarts by itself with Docker.
- Settings › Code runner is set to `http://127.0.0.1:2000` (switched on).
- Installed runtimes: bash, c / c++ (gcc 10.2), c# (mono 6.12), java 15, php 8.2, go 1.16, ruby 3.0, lua 5.4, perl 5.36, sqlite3 (the `sql` tag), typescript 5.0, kotlin 1.8, rust 1.68, swift 5.3. Piston has no package for **R**, so R blocks have no Run button.
- Checked through the real app (lecture Run endpoint): every language above printed its answer.

## Start it again / recreate it
```
docker run --privileged -v piston_packages:/piston/packages -d --restart unless-stopped --name piston_api \
  -p 127.0.0.1:2000:2000 -e PISTON_COMPILE_TIMEOUT=30000 -e PISTON_COMPILE_CPU_TIME=30000 \
  -e PISTON_RUN_TIMEOUT=10000 -e PISTON_RUN_CPU_TIME=10000 -e PISTON_OUTPUT_MAX_SIZE=65536 -e PISTON_MAX_PROCESS_COUNT=128 \
  ghcr.io/engineer-man/piston
```
(The longer compile limits are needed for Kotlin and Java. The packages live in the `piston_packages` volume, so recreating the container keeps them.)

## Add another language
```
curl -X POST http://127.0.0.1:2000/api/v2/packages -H 'Content-Type: application/json' -d '{"language":"gcc","version":"10.2.0"}'
curl http://127.0.0.1:2000/api/v2/packages     # everything that can be installed
```
Then add its tag to `codeRunnerLanguages()` in `includes/code_runner.php`.

## On a real server
Run the same container on the server (or another machine), keep port 2000 closed to the internet (the SmartClass server talks to it privately), and put its address in Settings › Code runner. The container is `--privileged`, so run it on a machine that holds nothing else important. SmartClass limits: 12 runs a minute per person, 20 000 characters of code, a few seconds of run time, no internet for the program.
