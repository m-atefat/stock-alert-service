#!/bin/sh
set -e

# The image deliberately does not COPY the app in at build time — the code
# arrives via the bind mount in docker-compose.yml. That means vendor/ is not
# guaranteed to exist yet (fresh clone, no local composer), so first boot
# prepares the app here rather than failing to find autoload.php.
#
# The php and supervisor services share this image AND the same bind mount,
# and start concurrently. Preparation therefore runs under an exclusive lock:
# whichever container arrives first does the work while the other blocks. That
# serialises `composer install` (two writing vendor/ at once corrupts it) and,
# just as importantly, holds supervisor's consumers and scheduler back until
# migrations are done — otherwise they spend their first seconds throwing on
# tables that do not exist.
LOCK_FILE=/var/www/html/storage/framework/boot.lock

exec 9>"$LOCK_FILE"
flock 9

if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "vendor/ missing, running composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

# .env.example ships APP_KEY empty so no key is ever committed. Generate one on
# first boot instead of making it a manual step the reader can skip — without
# it, migrations still run but every authenticated request fails.
if ! grep -qE '^APP_KEY=base64:' /var/www/html/.env 2>/dev/null; then
    echo "APP_KEY empty, generating..."
    php /var/www/html/artisan key:generate --force
fi

# Idempotent: on every boot after the first this prints "Nothing to migrate",
# and the second container through the lock sees the same.
echo "running database migrations..."
php /var/www/html/artisan migrate --force

# With options.queue.exchange set, the queue driver no longer auto-declares
# queues and nothing else creates them. Every declare uses identical arguments,
# so re-running each boot is a no-op. Without this, a fresh broker (first boot,
# or a wiped rabbitmq-data volume) has no exchanges, queues or bindings at all
# and the pipeline silently delivers nothing.
echo "declaring rabbitmq topology..."
php /var/www/html/artisan rabbitmq:declare-topology

exec 9>&-

exec "$@"
