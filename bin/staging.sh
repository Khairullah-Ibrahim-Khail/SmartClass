#!/bin/sh
# Run the staging copy (built by php bin/make_staging.php) on its own port; Ctrl+C stops it.
#   sh bin/staging.sh            →  http://127.0.0.1:8081/myProject/SmartClass/
# Needs no Apache settings: PHP's own server, started one folder level above the project so the address path matches.
APP="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(dirname "$(dirname "$APP")")"
echo "Staging copy on http://127.0.0.1:8081$(echo "$APP" | sed "s#^$ROOT##")/   (Ctrl+C to stop)"
SC_STAGING=1 exec php -S 127.0.0.1:8081 -t "$ROOT"
