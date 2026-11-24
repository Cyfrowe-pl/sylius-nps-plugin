#!/bin/sh
set -e

# The bind mount hides anything the image created at /app, so the writable
# directories have to be (re)created once the volume is in place.
for dir in \
    tests/Application/var/cache \
    tests/Application/var/log \
    tests/Application/public/media \
    var/cache \
    var/log
do
    mkdir -p "/app/${dir}"
done

chown -R www-data:www-data /app/tests/Application/var /app/tests/Application/public/media /app/var 2>/dev/null || true

exec "$@"
