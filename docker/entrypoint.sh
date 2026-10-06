#!/bin/sh
set -e
# var/ is a persistent volume, but the compiled Twig templates in it belong to one
# image. Twig re-checks template mtimes (auto_reload is always on), but a clean start
# also drops stale files of templates that no longer exist. Dropping the cache is cheap.
rm -rf /app/var/twig
# App\Session keeps sessions here, inside the persistent volume (it sets the save path
# itself, see src/Session.php). The volume may be fresh and root-owned, and the app runs
# as www-data, so the directory is created and handed over on every start.
mkdir -p /app/var/sessions
chown www-data:www-data /app/var/sessions
chmod 700 /app/var/sessions
exec "$@"
