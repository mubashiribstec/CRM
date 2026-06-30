#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════════════════
# CRM Laravel — hot update of the RUNNING containers (no image rebuild)
#
# Applies backend code changes (PHP / Blade / routes / config / migrations) to
# the already-running `crm_app` container without `docker compose build`.
#
# How it works:
#   1. (optional) git pull the latest code into this working tree
#   2. docker cp the application source into the running crm_app container
#   3. fix ownership of the copied files inside the container
#   4. restart the app service — its entrypoint then:
#        • clears the stale bootstrap cache
#        • runs `php artisan migrate --force` (idempotent / self-reconciling)
#        • rebuilds the config / route / view caches
#        • re-syncs the Vite assets to the shared volume
#        • starts a FRESH PHP-FPM master, which clears OPcache
#          (required: opcache.validate_timestamps=0 means changed files are
#           otherwise ignored until the FPM process is restarted)
#
# ── IMPORTANT: scope & limits ─────────────────────────────────────────────────
#   • This patches the running container's filesystem only. It is NOT baked into
#     the image: `docker compose up --force-recreate` / `docker rm` reverts to
#     the image's original code. To make changes PERMANENT, rebuild once:
#         docker compose up --build -d
#   • FRONTEND asset changes (resources/js, resources/css, *.vue, vite.config.js)
#     are compiled by Vite during the image build only. This script does NOT
#     rebuild them — if you changed assets, you must rebuild the image.
#     This script covers BACKEND code (PHP / Blade views / routes / config /
#     migrations) — exactly the kind of change that does not need a build.
#
# ── Usage ─────────────────────────────────────────────────────────────────────
#   ./docker/update.sh             # deploy the current working tree
#   ./docker/update.sh --pull      # `git pull` first, then deploy
#   ./docker/update.sh --pull --branch main
# ══════════════════════════════════════════════════════════════════════════════
set -euo pipefail

# ── Resolve project root (the dir that holds docker-compose.yml) ──────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

APP_SVC="app"                 # docker-compose service name
APP_DEST="/var/www/html"      # working_dir inside the container

# Application source paths to copy (BACKEND code only — see limits above).
# Note: storage/ and public/build are Docker volumes and are intentionally
# never touched here.
SRC_DIRS=(app config database resources routes)
[ -d lang ] && SRC_DIRS+=(lang)
SRC_FILES=(bootstrap/app.php)

# ── Parse args ────────────────────────────────────────────────────────────────
DO_PULL=0
BRANCH=""
while [ $# -gt 0 ]; do
    case "$1" in
        --pull)   DO_PULL=1 ;;
        --branch) shift; BRANCH="${1:-}" ;;
        -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
    shift
done

log()  { echo -e "\033[1;34m[update]\033[0m $*"; }
fail() { echo -e "\033[1;31m[update] FATAL:\033[0m $*" >&2; exit 1; }

# ── Pre-flight checks ─────────────────────────────────────────────────────────
command -v docker >/dev/null 2>&1 || fail "docker not found in PATH."
docker compose version >/dev/null 2>&1 || fail "'docker compose' (v2) is required."
[ -f docker-compose.yml ] || fail "docker-compose.yml not found in ${PROJECT_ROOT}."

CID="$(docker compose ps -q "${APP_SVC}" 2>/dev/null || true)"
[ -n "${CID}" ] || fail "The '${APP_SVC}' container is not running. Start it with: docker compose up -d"

# ── 1. Optional git pull ──────────────────────────────────────────────────────
if [ "${DO_PULL}" = "1" ]; then
    CUR_BRANCH="${BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"
    log "Pulling latest code (origin/${CUR_BRANCH}) ..."
    git fetch origin "${CUR_BRANCH}"
    git pull --ff-only origin "${CUR_BRANCH}" \
        || fail "git pull was not fast-forward. Resolve manually, then re-run."
fi

log "Deploying commit: $(git rev-parse --short HEAD 2>/dev/null || echo 'n/a') into container ${CID:0:12}"

# ── 2. Copy source into the running container ─────────────────────────────────
# `src/.` copies the CONTENTS of src into the existing destination dir, merging
# (overwrites changed files, adds new ones). Files removed in git are not pruned
# by docker cp — for that rare case, do a full image rebuild.
for d in "${SRC_DIRS[@]}"; do
    [ -d "${d}" ] || continue
    log "  → ${d}/"
    docker cp "${PROJECT_ROOT}/${d}/." "${CID}:${APP_DEST}/${d}"
done
for f in "${SRC_FILES[@]}"; do
    [ -f "${f}" ] || continue
    log "  → ${f}"
    docker cp "${PROJECT_ROOT}/${f}" "${CID}:${APP_DEST}/${f}"
done

# ── 3. Fix ownership of copied files (docker cp lands them as root) ────────────
log "Fixing ownership inside container ..."
OWN_TARGETS=""
for d in "${SRC_DIRS[@]}"; do OWN_TARGETS="${OWN_TARGETS} ${APP_DEST}/${d}"; done
# shellcheck disable=SC2086
docker exec "${CID}" sh -c "chown -R www-data:www-data ${OWN_TARGETS} ${APP_DEST}/bootstrap/app.php 2>/dev/null || true"

# ── 4. Restart the app service (entrypoint does migrations + caches + OPcache) ─
log "Restarting '${APP_SVC}' (migrations, cache rebuild, fresh PHP-FPM/OPcache) ..."
docker compose restart "${APP_SVC}"

# ── 5. Show the entrypoint result so you can confirm migrations/caches ────────
log "Recent app logs:"
sleep 3
docker compose logs --tail=40 "${APP_SVC}" || true

log "Done. Backend changes are live on the running containers."
log "Remember: rebuild the image ('docker compose up --build -d') to make this permanent,"
log "and for any frontend/asset (Vite) changes."
