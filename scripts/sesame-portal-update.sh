#!/usr/bin/env bash
set -euo pipefail

REPO="rosteleset/SesamePortal"
REF="main"
INSTALL_LINK="/opt/sesame-portal/current"
STATE_DIR="/var/lib/sesame-portal"
PHP_BIN="${PHP_BIN:-php}"
CONFIG_FILE="${SESAME_PORTAL_UPDATE_CONFIG:-/etc/sesame-portal-update.conf}"

usage() {
  cat <<'USAGE'
Usage:
  sesame-portal-update [--repo owner/repo] [--ref main] [--install-link /opt/sesame-portal/current] [--state-dir /var/lib/sesame-portal] [--php-bin php]

Downloads the selected GitHub ref, installs it as a new SesamePortal release,
switches the current symlink atomically, runs migrations, and reloads php-fpm.
USAGE
}

if [[ -f "$CONFIG_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$CONFIG_FILE"
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo) REPO="${2:-}"; shift 2 ;;
    --ref) REF="${2:-}"; shift 2 ;;
    --install-link) INSTALL_LINK="${2:-}"; shift 2 ;;
    --state-dir) STATE_DIR="${2:-}"; shift 2 ;;
    --php-bin) PHP_BIN="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
  esac
done

if [[ "$(id -u)" != "0" ]]; then
  echo "updater must run as root" >&2
  exit 1
fi
if [[ -z "$REPO" || "$REPO" != */* ]]; then
  echo "--repo must be in owner/repo format" >&2
  exit 2
fi
if [[ -z "$REF" || -z "$INSTALL_LINK" || -z "$STATE_DIR" ]]; then
  echo "--ref, --install-link and --state-dir are required" >&2
  exit 2
fi
if ! command -v curl >/dev/null 2>&1; then
  echo "curl not found" >&2
  exit 1
fi
if ! command -v tar >/dev/null 2>&1; then
  echo "tar not found" >&2
  exit 1
fi
if ! command -v rsync >/dev/null 2>&1; then
  echo "rsync not found" >&2
  exit 1
fi
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "php not found: $PHP_BIN" >&2
  exit 1
fi

LOCK_FILE="/var/lock/sesame-portal-update.lock"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  echo "another SesamePortal update is already running" >&2
  exit 1
fi

TMP_DIR="$(mktemp -d /tmp/sesame-portal-update.XXXXXX)"
CURRENT_BACKUP=""
PREVIOUS_TARGET=""
LINK_CHANGED=0
SUCCESS=0

cleanup() {
  local rc="$?"
  if [[ "$SUCCESS" != "1" && "$LINK_CHANGED" == "1" ]]; then
    set +e
    if [[ -n "$PREVIOUS_TARGET" ]]; then
      ln -sfn "$PREVIOUS_TARGET" "$INSTALL_LINK"
    elif [[ -n "$CURRENT_BACKUP" && -e "$CURRENT_BACKUP" ]]; then
      rm -rf "$INSTALL_LINK"
      mv "$CURRENT_BACKUP" "$INSTALL_LINK"
    fi
  fi
  rm -rf "$TMP_DIR"
  exit "$rc"
}
trap cleanup EXIT

curl_headers=(-H "Accept: application/vnd.github+json" -H "User-Agent: SesamePortal-Updater")
if [[ -n "${SESAME_PORTAL_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}" ]]; then
  curl_headers+=(-H "Authorization: Bearer ${SESAME_PORTAL_GITHUB_TOKEN:-${GITHUB_TOKEN:-}}")
fi

COMMIT_JSON="$TMP_DIR/commit.json"
API_URL="https://api.github.com/repos/$REPO/commits/$REF"
curl -fsSL --connect-timeout 5 --max-time 30 "${curl_headers[@]}" "$API_URL" -o "$COMMIT_JSON"

SHA="$("$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]), true); echo is_array($d) ? (string)($d["sha"] ?? "") : "";' "$COMMIT_JSON")"
COMMIT_DATE="$("$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]), true); echo is_array($d) ? (string)($d["commit"]["committer"]["date"] ?? $d["commit"]["author"]["date"] ?? "") : "";' "$COMMIT_JSON")"
if [[ -z "$SHA" ]]; then
  echo "GitHub response does not contain commit sha" >&2
  exit 1
fi

INSTALL_PARENT="$(dirname "$INSTALL_LINK")"
RELEASES_DIR="$INSTALL_PARENT/releases"
BACKUPS_DIR="$INSTALL_PARENT/backups"
BUILD_TIME="$(date -u +"%Y%m%dT%H%M%SZ")"
BUILD_ID="${SHA:0:12}-$BUILD_TIME"
RELEASE_DIR="$RELEASES_DIR/$BUILD_ID"

install -d -m 0755 "$INSTALL_PARENT" "$RELEASES_DIR" "$BACKUPS_DIR"
rm -rf "$RELEASE_DIR"
install -d -m 0755 "$RELEASE_DIR"

ARCHIVE="$TMP_DIR/source.tar.gz"
curl -fsSL --connect-timeout 5 --max-time 120 "${curl_headers[@]}" "https://github.com/$REPO/archive/$SHA.tar.gz" -o "$ARCHIVE"
mkdir -p "$TMP_DIR/extract"
tar -xzf "$ARCHIVE" -C "$TMP_DIR/extract"
SOURCE_DIR="$(find "$TMP_DIR/extract" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
if [[ -z "$SOURCE_DIR" || ! -d "$SOURCE_DIR" ]]; then
  echo "downloaded archive does not contain source directory" >&2
  exit 1
fi

rsync -a --delete \
  --exclude '.git/' \
  --exclude '_dist/' \
  --exclude 'var/' \
  --exclude '*.sqlite' \
  --exclude '*.sqlite-shm' \
  --exclude '*.sqlite-wal' \
  "$SOURCE_DIR/" "$RELEASE_DIR/"
chmod +x "$RELEASE_DIR/bin/portal" "$RELEASE_DIR/scripts/install.sh" "$RELEASE_DIR/scripts/package-release.sh" "$RELEASE_DIR/scripts/sesame-portal-update.sh"

cat > "$RELEASE_DIR/RELEASE.json" <<JSON
{
  "name": "SesamePortal",
  "version": "$BUILD_ID",
  "sourceCommit": "$SHA",
  "dirty": false,
  "builtAt": "$BUILD_TIME",
  "commitDate": "$COMMIT_DATE",
  "updateSource": "github",
  "updateRepo": "$REPO",
  "updateRef": "$REF"
}
JSON

if [[ -L "$INSTALL_LINK" ]]; then
  PREVIOUS_TARGET="$(readlink -f "$INSTALL_LINK" || true)"
else
  if [[ -e "$INSTALL_LINK" ]]; then
    CURRENT_BACKUP="$BACKUPS_DIR/current-prev-$BUILD_TIME"
    rm -rf "$CURRENT_BACKUP"
    mv "$INSTALL_LINK" "$CURRENT_BACKUP"
  fi
fi

NEXT_LINK="$INSTALL_PARENT/.sesame-portal-current-next.$$"
ln -sfn "$RELEASE_DIR" "$NEXT_LINK"
mv -Tf "$NEXT_LINK" "$INSTALL_LINK"
LINK_CHANGED=1

if id www-data >/dev/null 2>&1 && command -v sudo >/dev/null 2>&1; then
  sudo -u www-data \
    SESAME_PORTAL_STATE_DIR="$STATE_DIR" \
    SESAME_PORTAL_CONFIG="$STATE_DIR/config.php" \
    "$PHP_BIN" "$INSTALL_LINK/bin/portal" migrate
elif id www-data >/dev/null 2>&1 && command -v runuser >/dev/null 2>&1; then
  runuser -u www-data -- env \
    SESAME_PORTAL_STATE_DIR="$STATE_DIR" \
    SESAME_PORTAL_CONFIG="$STATE_DIR/config.php" \
    "$PHP_BIN" "$INSTALL_LINK/bin/portal" migrate
else
  SESAME_PORTAL_STATE_DIR="$STATE_DIR" \
    SESAME_PORTAL_CONFIG="$STATE_DIR/config.php" \
    "$PHP_BIN" "$INSTALL_LINK/bin/portal" migrate
fi

if command -v systemctl >/dev/null 2>&1; then
  while read -r unit; do
    [[ -z "$unit" ]] && continue
    systemctl reload "$unit" >/dev/null 2>&1 || systemctl restart "$unit" >/dev/null 2>&1 || true
  done < <(systemctl list-units --type=service --all 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}')
fi

SUCCESS=1
echo "SesamePortal updated"
echo "repo=$REPO"
echo "ref=$REF"
echo "sourceCommit=$SHA"
echo "release=$RELEASE_DIR"
