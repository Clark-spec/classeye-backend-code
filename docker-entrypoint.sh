#!/bin/sh
set -e

cd /var/www/html

# ---------------------------------------------------------------------------
# 1. Bootstrap the Laravel skeleton on the very first run.
#    The Docker image already contains the application and Composer vendor
#    directory; this fallback only applies to a completely empty bind mount.
# ---------------------------------------------------------------------------
if [ ! -f composer.json ]; then
    echo "==> Bootstrapping Laravel 12 skeleton..."
    rm -rf /tmp/laravel-skeleton
    composer create-project --prefer-dist "laravel/laravel:^12.0" /tmp/laravel-skeleton --no-interaction

    # Never let the skeleton's throw-away .env / sqlite database win.
    rm -f /tmp/laravel-skeleton/.env /tmp/laravel-skeleton/database/database.sqlite

    rsync -a --ignore-existing /tmp/laravel-skeleton/ /var/www/html/
    rm -rf /tmp/laravel-skeleton
fi

# ---------------------------------------------------------------------------
# 2. Composer dependencies.
# ---------------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
    echo "==> Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

# ---------------------------------------------------------------------------
# 3. Environment file and application key (idempotent).
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
    echo "==> Creating .env from .env.example..."
    cp .env.example .env
fi

if [ -z "${APP_KEY:-}" ] && ! grep -q "^APP_KEY=base64:" .env; then
    echo "==> Generating application key..."
    php artisan key:generate --force
fi

# ---------------------------------------------------------------------------
# 4. Sanctum. install:api creates the personal_access_tokens migration and
#    registers the API routes. Our routes/api.php already exists, so the
#    command leaves it alone (it only reports that the file exists).
# ---------------------------------------------------------------------------
if [ ! -d vendor/laravel/sanctum ] || ! ls database/migrations/*_create_personal_access_tokens_table.php >/dev/null 2>&1; then
    echo "==> Installing Laravel Sanctum..."
    php artisan install:api --no-interaction --without-migration-prompt
fi

# ---------------------------------------------------------------------------
# 5. Wait until the configured database (local PostgreSQL or Neon) is ready.
#    Laravel's own connection handles DB_URL, SSL mode, and other options.
# ---------------------------------------------------------------------------
echo "==> Waiting for database..."
ATTEMPTS=0
until php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::connection()->getPdo();
    exit(0);
} catch (Throwable $e) {
    exit(1);
}
' 2>/dev/null; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "$ATTEMPTS" -ge 90 ]; then
        echo "==> Database is not reachable. Check DB_CONNECTION, DB_URL/DB_HOST, and credentials."
        exit 1
    fi
    sleep 2
done

# ---------------------------------------------------------------------------
# 6. Migrate, then seed only when the database has no users yet.
# ---------------------------------------------------------------------------
echo "==> Running migrations..."
php artisan migrate --force

USER_COUNT=$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    echo Illuminate\Support\Facades\DB::table("users")->count();
} catch (Throwable $e) {
    echo 0;
}
' 2>/dev/null || echo 0)

if [ "${SEED_DEMO_DATA:-true}" != "false" ] && [ "${USER_COUNT:-0}" = "0" ]; then
    echo "==> Seeding demo data..."
    php artisan db:seed --force
fi

php artisan config:clear >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# 7. Serve the API on Render's PORT (or 8000 locally).
# ---------------------------------------------------------------------------
if [ -n "${RENDER:-}" ]; then
    PORT="${PORT:-10000}"
fi
PORT="${PORT:-8000}"
echo "==> Class Eye API ready at http://localhost:${PORT}"
exec php artisan serve --host=0.0.0.0 --port="${PORT}"
