#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# CRM Laravel — Docker entrypoint
#
# Intentionally NOT using "set -e" so expected non-zero exits (e.g. migrate
# when SQL dump already created tables) don't kill the process.
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

APP_ROOT=/var/www/html
BOOTSTRAP_CACHE="${APP_ROOT}/bootstrap/cache"
SEED_MARKER="${APP_ROOT}/storage/.docker_seeded"

log()  { echo "[entrypoint] $*"; }
fail() { echo "[entrypoint] FATAL: $*" >&2; exit 1; }

log "── CRM Laravel startup ──────────────────────────────────────────"

# ── 0. Clear any stale bootstrap cache ───────────────────────────────────────
# Must happen FIRST — a cached config with an empty APP_KEY causes every
# artisan command (including key:generate) to throw MissingAppKeyException.
log "Clearing bootstrap cache ..."
rm -f "${BOOTSTRAP_CACHE}/config.php" \
      "${BOOTSTRAP_CACHE}/routes-v7.php" \
      "${BOOTSTRAP_CACHE}/services.php" \
      "${BOOTSTRAP_CACHE}/events.php"

# ── 1. Ensure .env exists ─────────────────────────────────────────────────────
if [ ! -f "${APP_ROOT}/.env" ]; then
    log ".env not found — copying .env.example"
    cp "${APP_ROOT}/.env.example" "${APP_ROOT}/.env" \
        || fail "Cannot create .env"
fi

# Patch .env with Docker-injected env vars
patch_env() {
    local key="$1" val="$2"
    if grep -q "^${key}=" "${APP_ROOT}/.env" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${val}|" "${APP_ROOT}/.env"
    else
        echo "${key}=${val}" >> "${APP_ROOT}/.env"
    fi
}

patch_env "DB_HOST"         "${DB_HOST:-db}"
patch_env "DB_PORT"         "${DB_PORT:-3306}"
patch_env "DB_DATABASE"     "${DB_DATABASE:-crm_db_portal}"
patch_env "DB_USERNAME"     "${DB_USERNAME:-crm_user}"
patch_env "DB_PASSWORD"     "${DB_PASSWORD:-admin@123}"
patch_env "APP_URL"         "${APP_URL:-http://localhost:8000}"
patch_env "APP_ENV"         "${APP_ENV:-local}"
patch_env "APP_DEBUG"       "${APP_DEBUG:-true}"
patch_env "TRUSTED_PROXIES" "${TRUSTED_PROXIES:-*}"
patch_env "SKIP_IP_CHECK"   "${SKIP_IP_CHECK:-true}"

# ── 2. Generate APP_KEY if missing ────────────────────────────────────────────
# We use `php -r` directly (NOT `php artisan key:generate`) because artisan
# needs to bootstrap the app, which requires APP_KEY to already be set.
# Using plain PHP avoids the chicken-and-egg completely.
CURRENT_KEY=$(grep '^APP_KEY=' "${APP_ROOT}/.env" | cut -d= -f2- | tr -d '"' | tr -d "'" | xargs 2>/dev/null || true)

if [ -z "${CURRENT_KEY}" ]; then
    log "APP_KEY is missing — generating with php -r ..."
    NEW_KEY=$(php -r "echo 'base64:' . base64_encode(random_bytes(32));") \
        || fail "php -r failed to generate APP_KEY"
    patch_env "APP_KEY" "${NEW_KEY}"
    # ── CRITICAL: export into the current shell so every artisan subprocess
    # (config:cache, migrate, etc.) inherits the real key.
    # Laravel uses Dotenv::createImmutable() which never overrides an existing
    # env var — Docker passes APP_KEY="" into the container, so without this
    # export artisan sees an empty key even after we wrote it to .env.
    export APP_KEY="${NEW_KEY}"
    log "APP_KEY generated."
else
    log "APP_KEY already set."
    # Export even when the key was already present — same Dotenv immutability
    # issue applies if the Docker env var is still empty or stale.
    export APP_KEY="${CURRENT_KEY}"
fi

# ── 3. Wait for MariaDB ───────────────────────────────────────────────────────
DB_HOST_VAL="${DB_HOST:-db}"
DB_PORT_VAL="${DB_PORT:-3306}"
DB_USER_VAL="${DB_USERNAME:-crm_user}"
DB_PASS_VAL="${DB_PASSWORD:-admin@123}"
DB_NAME_VAL="${DB_DATABASE:-crm_db_portal}"

log "Waiting for MariaDB at ${DB_HOST_VAL}:${DB_PORT_VAL} ..."
MAX=90; COUNT=0
until mysqladmin ping \
        -h"${DB_HOST_VAL}" -P"${DB_PORT_VAL}" \
        -u"${DB_USER_VAL}" -p"${DB_PASS_VAL}" \
        --silent 2>/dev/null; do
    COUNT=$((COUNT + 1))
    [ "${COUNT}" -ge "${MAX}" ] && fail "Database not ready after ${MAX}s."
    [ $((COUNT % 10)) -eq 0 ] && log "  still waiting … (${COUNT}/${MAX}s)"
    sleep 1
done
log "MariaDB is ready."

cd "${APP_ROOT}"

# ── Small MySQL helpers (reused by the migration logic below) ────────────────
# _sql  <statement>  → run a statement, ignore output
# _scalar <query>    → run a query, echo the single returned value (0 on error)
_sql() {
    mysql -h"${DB_HOST_VAL}" -P"${DB_PORT_VAL}" \
          -u"${DB_USER_VAL}" -p"${DB_PASS_VAL}" "${DB_NAME_VAL}" \
          -e "$1" 2>/dev/null
}
_scalar() {
    mysql -h"${DB_HOST_VAL}" -P"${DB_PORT_VAL}" \
          -u"${DB_USER_VAL}" -p"${DB_PASS_VAL}" "${DB_NAME_VAL}" \
          -se "$1" 2>/dev/null || echo "0"
}

# ── 4. Smart migrations ───────────────────────────────────────────────────────
# The SQL dump imports all tables but leaves the migrations table empty.
# Running `migrate` then fails on "Table users already exists" and never
# reaches new migrations (call_logs, dial_locks, etc.).
#
# Fix: if migrate fails AND tables already exist, reconcile the migrations
# table by applying each migration individually, IN ORDER:
#   • migrations whose schema is already present fail harmlessly and are simply
#     recorded as applied (baseline) so they are never retried;
#   • genuinely new migrations (e.g. dial_locks) run and are recorded by Laravel.
# This is correct for BOTH create- and alter-migrations without guessing which
# tables/columns a migration touches — the previous version blindly marked ALL
# migrations as complete, which left new tables (dial_locks) permanently absent.
log "Running migrations ..."
if ! php artisan migrate --force --ansi 2>&1; then
    USERS_EXISTS=$(_scalar "SELECT COUNT(*) FROM information_schema.tables \
                            WHERE table_schema='${DB_NAME_VAL}' AND table_name='users';")

    if [ "${USERS_EXISTS}" = "1" ]; then
        log "Existing schema detected (SQL dump) — reconciling migrations one-by-one ..."
        for f in $(find "${APP_ROOT}/database/migrations" -name "*.php" | sort); do
            name=$(basename "$f" .php)
            rel="database/migrations/$(basename "$f")"

            # Already recorded? nothing to do.
            DONE=$(_scalar "SELECT COUNT(*) FROM migrations WHERE migration='${name}';")
            [ "${DONE}" != "0" ] && continue

            if php artisan migrate --force --ansi --path="${rel}" >/dev/null 2>&1; then
                log "  applied  ${name}"
            else
                # Schema already present from the dump → mark applied, don't retry.
                _sql "INSERT IGNORE INTO migrations (migration,batch) VALUES ('${name}',1);"
                log "  baseline ${name} (schema already present)"
            fi
        done
    else
        log "WARN: Migration failed and users table not found — DB may be empty."
    fi
fi
log "Migrations done."

# ── 4b. Self-heal feature schema ─────────────────────────────────────────────
# Older entrypoint versions baselined ALL migrations at once, marking some as
# "applied" without ever creating their tables/columns. That left databases
# where, e.g., `dial_locks` is recorded as migrated but does not exist — causing
# "Base table or view not found: dial_locks" on click-to-dial.
#
# For each feature migration, if its schema is actually missing we drop the
# stale migration record and re-apply just that migration. All listed migrations
# are idempotent (or only run here when their target is absent), so this is safe
# to run on every startup; it is a no-op once the schema is present.
heal() {                                  # $1 = migration name   $2 = "exists" query
    local name="$1" test_sql="$2"
    if [ "$(_scalar "${test_sql}")" = "0" ]; then
        log "Self-heal: schema for ${name} missing — re-applying ..."
        _sql "DELETE FROM migrations WHERE migration='${name}';"
        php artisan migrate --force --ansi --path="database/migrations/${name}.php" 2>&1 \
            || log "WARN: self-heal of ${name} failed."
    fi
}
SCHEMA="table_schema='${DB_NAME_VAL}'"
heal "2026_05_28_170000_ensure_call_logs_table" \
     "SELECT COUNT(*) FROM information_schema.tables  WHERE ${SCHEMA} AND table_name='call_logs';"
heal "2026_05_28_180000_create_dial_locks_table" \
     "SELECT COUNT(*) FROM information_schema.tables  WHERE ${SCHEMA} AND table_name='dial_locks';"
heal "2026_05_28_190000_add_call_count_to_dial_locks" \
     "SELECT COUNT(*) FROM information_schema.columns WHERE ${SCHEMA} AND table_name='dial_locks' AND column_name='call_count';"
heal "2026_05_28_163825_add_sip_credentials_to_users_table" \
     "SELECT COUNT(*) FROM information_schema.columns WHERE ${SCHEMA} AND table_name='users' AND column_name='sip_extension';"
heal "2026_05_29_120000_add_normalized_phone_columns_to_applicants_table" \
     "SELECT COUNT(*) FROM information_schema.columns WHERE ${SCHEMA} AND table_name='applicants' AND column_name='applicant_phone_normalized';"
log "Schema self-heal done."

# ── 5. Seed database on first start ───────────────────────────────────────────
# SEED_DATABASE: auto | true | force | false  (default: auto)
SEED_MODE="${SEED_DATABASE:-auto}"

should_seed() {
    case "${SEED_MODE}" in
        force)  return 0 ;;
        false)  return 1 ;;
        true)   [ -f "${SEED_MARKER}" ] && return 1 || return 0 ;;
        auto|*)
            COUNT_USERS=$(mysql \
                -h"${DB_HOST_VAL}" -P"${DB_PORT_VAL}" \
                -u"${DB_USER_VAL}" -p"${DB_PASS_VAL}" "${DB_NAME_VAL}" \
                -se "SELECT COUNT(*) FROM users;" 2>/dev/null || echo "1")
            [ "${COUNT_USERS}" = "0" ] && return 0 || return 1 ;;
    esac
}

if should_seed; then
    log "Seeding database (SEED_DATABASE=${SEED_MODE}) ..."
    if php artisan db:seed --force --ansi 2>&1; then
        touch "${SEED_MARKER}"
        log "Seeding complete. Login: admin@crm.local / Admin@1234!"
    else
        log "WARN: Seeding failed — check logs."
    fi
else
    log "Database already has data — skipping seed."
fi

# ── 6. Storage symlink ────────────────────────────────────────────────────────
log "Linking storage ..."
php artisan storage:link --force 2>/dev/null || true

# ── 6b. Sync Vite assets → shared Docker volume ───────────────────────────────
# The public/build directory is a named volume shared with the Nginx container.
# Docker volumes hide the image's content at mount time, so we keep a copy at
# public_build_src/ (never volume-mounted) and sync it here on every startup.
# This ensures Nginx always serves the assets that match the current image build.
if [ -d "${APP_ROOT}/public_build_src" ]; then
    log "Syncing Vite built assets to shared volume ..."
    cp -r "${APP_ROOT}/public_build_src/." "${APP_ROOT}/public/build/" \
      && log "Vite assets synced." \
      || log "WARN: Could not sync Vite assets — Nginx may serve stale or missing CSS/JS."
fi

# ── 7. Cache config / routes / views ─────────────────────────────────────────
# At this point APP_KEY is in .env, so config:cache will capture it correctly.
log "Caching config ..."
php artisan config:cache --ansi 2>&1 || log "WARN: config:cache failed."

log "Caching routes ..."
php artisan route:cache  --ansi 2>&1 || log "WARN: route:cache failed — check for duplicate route names."

log "Caching views ..."
php artisan view:cache   --ansi 2>&1 || log "WARN: view:cache failed."

# ── 8. Permissions ────────────────────────────────────────────────────────────
log "Setting permissions ..."
chown -R www-data:www-data "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" 2>/dev/null || true
chmod -R 775               "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" 2>/dev/null || true
mkdir -p /var/log/php && chown www-data:www-data /var/log/php 2>/dev/null || true

log "── Startup complete. Starting PHP-FPM ──────────────────────────"
exec "$@"
