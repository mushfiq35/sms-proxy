#!/bin/bash
set -e

# Render sets $PORT; default to 80 if running locally without it.
PORT="${PORT:-80}"

# Render puts Secret Files in /etc/secrets (readable by root only). Copy the service-account key
# to a private folder OUTSIDE the web root that Apache (www-data) can read.
mkdir -p /var/private
if [ -f /etc/secrets/firebase-sa.json ]; then
  cp /etc/secrets/firebase-sa.json /var/private/firebase-sa.json
  chown www-data:www-data /var/private/firebase-sa.json
  chmod 600 /var/private/firebase-sa.json
fi
rm -f /var/www/html/*.json

sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf

exec apache2-foreground
