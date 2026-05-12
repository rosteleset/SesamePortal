#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_DIR="$(mktemp -d)"
PORT="${SESAME_PORTAL_TEST_PORT:-18089}"
COOKIE_JAR="$STATE_DIR/cookies.txt"
SERVER_LOG="$STATE_DIR/server.log"

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$STATE_DIR"
}
trap cleanup EXIT

export SESAME_PORTAL_STATE_DIR="$STATE_DIR"
export SESAME_PORTAL_SECRET="test-secret"

php "$ROOT/bin/portal" migrate >/dev/null
php "$ROOT/bin/portal" create-admin admin admin123 >/dev/null

php -S "127.0.0.1:$PORT" -t "$ROOT/public" >"$SERVER_LOG" 2>&1 &
SERVER_PID="$!"
sleep 0.4

login_page="$(curl -fsS -c "$COOKIE_JAR" "http://127.0.0.1:$PORT/login")"
csrf="$(printf "%s" "$login_page" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
test -n "$csrf"

status="$(
  curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -d "csrf=$csrf" -d "login=admin" -d "password=admin123" \
    "http://127.0.0.1:$PORT/login"
)"
test "$status" = "303"

curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/dashboard" | grep -q "SesameDVR серверы"
curl -fsS -b "$COOKIE_JAR" "http://127.0.0.1:$PORT/admin/users?q=admin" | grep -q "admin"

denied="$(
  curl -sS -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:$PORT/api/sesamedvr/auth?token=bad&camera=missing"
)"
test "$denied" = "403"

echo "http smoke ok"
