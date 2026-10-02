# List available recipes.
default:
    @just --list

# Install production dependencies and assemble the FTP release.
release:
    #!/usr/bin/env bash
    set -euo pipefail
    composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
    rm -rf release
    mkdir release
    cp -a src templates events vendor www release/

# Sync the prepared release using FTP_HOST, FTP_USER and FTP_PASS from the environment.
deploy:
    #!/usr/bin/env bash
    set -euo pipefail
    : "${FTP_HOST:?Missing FTP_HOST}"
    : "${FTP_USER:?Missing FTP_USER}"
    : "${FTP_PASS:?Missing FTP_PASS}"
    test -f release/www/index.php
    lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" -e '
      set cmd:fail-exit true;
      set ftp:ssl-force true;
      set ftp:ssl-protect-data true;
      set ftp:ssl-auth TLS;
      set ftp:passive-mode true;
      set ssl:verify-certificate yes;
      mirror -R --delete --no-perms --upload-older --exclude-glob .env --exclude-glob var/ --exclude-glob __log/ --exclude-glob tmp/ --exclude-glob www/.well-known/ --exclude-glob www/.user.ini --exclude-glob www/cgi-bin/ release .;
      bye'
