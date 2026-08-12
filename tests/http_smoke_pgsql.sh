#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PGHOST="${SESAME_PORTAL_TEST_PG_HOST:-127.0.0.1}"
PGPORT="${SESAME_PORTAL_TEST_PG_PORT:-5432}"
PGDATABASE="${SESAME_PORTAL_TEST_PG_DB:-sesame_portal_test}"
PGUSER="${SESAME_PORTAL_TEST_PG_USER:-sesame}"
PGPASSWORD="${SESAME_PORTAL_TEST_PG_PASSWORD:-sesame-pass}"

export PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD

echo "preparing pgsql test database: ${PGUSER}@${PGHOST}:${PGPORT}/${PGDATABASE}"
psql -v ON_ERROR_STOP=1 \
  -c 'DROP SCHEMA IF EXISTS public CASCADE' \
  -c 'CREATE SCHEMA public'

export SESAME_PORTAL_DB_DSN="pgsql:host=${PGHOST};port=${PGPORT};dbname=${PGDATABASE}"
export SESAME_PORTAL_DB_USER="${PGUSER}"
export SESAME_PORTAL_DB_PASSWORD="${PGPASSWORD}"

exec bash "${ROOT}/tests/http_smoke.sh"
