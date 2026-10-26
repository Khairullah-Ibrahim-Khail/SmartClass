#!/bin/sh
# One-time fix for LOCAL Apache on this kind of machine (systemd "ProtectHome=read-only"): lets the web server write the
# uploads, backups, sessions, logs and customer folders of this project. Run it ONCE, as your normal user; it asks for your sudo password.
#   sh bin/fix_local_apache.sh
set -e
APP="$(cd "$(dirname "$0")/.." && pwd)"
DROPIN=/etc/systemd/system/apache2.service.d
echo "Allowing Apache to write in: $APP/{uploads,backups,sessions,logs,instances,config/instances}"
sudo mkdir -p "$DROPIN"
printf '[Service]\nReadWritePaths=%s/uploads %s/backups %s/sessions %s/logs %s/instances %s/config/instances\n' "$APP" "$APP" "$APP" "$APP" "$APP" "$APP" | sudo tee "$DROPIN/smartclass.conf" >/dev/null
for d in uploads backups sessions logs instances config/instances; do sudo mkdir -p "$APP/$d"; sudo chown -R www-data:www-data "$APP/$d"; sudo chmod -R u+rwX,g+rwX "$APP/$d"; done
sudo systemctl daemon-reload
sudo systemctl restart apache2
echo "Done. Open Settings › System check in the app to confirm that uploads now work."
