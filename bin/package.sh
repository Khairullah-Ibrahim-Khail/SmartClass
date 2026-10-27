#!/bin/sh
# Build a clean release archive for the server (no .git, tests, QA files, local secrets, uploads, backups):
#   sh bin/package.sh            -> ../smartclass-release-<date>.tar.gz
# Upload it, unpack in the web folder, then follow docs/installation/installation-guide.pdf.
set -e
cd "$(dirname "$0")/.."
OUT="../smartclass-release-$(date +%Y%m%d).tar.gz"
git ls-files --cached --others --exclude-standard \
  | grep -vE '^(tests/|qa/|designs/|\.githooks/|TODO\.md$|\.gitignore$|docs/.*\.(tex|aux|log)$|docs/common/|docs/build\.sh$|config/local\.php$|config/instances/)' \
  | grep -vE '^(uploads|backups|sessions|logs|instances)/' > /tmp/smartclass-files.$$
# keep the protective .htaccess files of the empty runtime folders
for d in uploads backups logs instances config/instances; do [ -f "$d/.htaccess" ] && echo "$d/.htaccess" >> /tmp/smartclass-files.$$; done
sort -u /tmp/smartclass-files.$$ | tar -czf "$OUT" -T -
rm -f /tmp/smartclass-files.$$
echo "$OUT  ($(du -h "$OUT" | cut -f1), $(tar -tzf "$OUT" | wc -l) files)"
