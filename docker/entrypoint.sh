#!/bin/sh
set -e
# var/ is a persistent volume, but the compiled Twig templates in it belong to one
# image. Twig re-checks template mtimes (auto_reload is always on), but a clean start
# also drops stale files of templates that no longer exist. Dropping the cache is cheap.
rm -rf /app/var/twig
# php.ini keeps sessions here, inside the persistent volume (see docker/php.ini). The
# volume may be fresh and root-owned, so the directory is created on every start.
mkdir -p /app/var/sessions
chown www-data:www-data /app/var/sessions
chmod 700 /app/var/sessions
exec "$@"
