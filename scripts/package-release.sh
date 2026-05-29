#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT/_dist"
VERSION=""
OUTPUT=""

usage() {
  cat <<'USAGE'
Usage:
  bash scripts/package-release.sh [--version 1.0.0] [--dist-dir _dist] [--output sesame-portal.tar.gz]

Options:
  --version <value>   Release version. Defaults to git describe or short commit.
  --dist-dir <path>   Artifact output directory. Default: _dist.
  --output <path>     Explicit tar.gz path. Default: <dist-dir>/sesame-portal-<version>.tar.gz.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION="${2:-}"; shift 2 ;;
    --dist-dir) DIST_DIR="${2:-}"; shift 2 ;;
    --output) OUTPUT="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
  esac
done

if [[ -z "$VERSION" ]]; then
  if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    VERSION="$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null)"
  else
    VERSION="dev"
  fi
fi

if [[ -z "$OUTPUT" ]]; then
  OUTPUT="$DIST_DIR/sesame-portal-$VERSION.tar.gz"
fi

STAGING="$(mktemp -d /tmp/sesame-portal-package.XXXXXX)"
PACKAGE_DIR="$STAGING/sesame-portal"
mkdir -p "$PACKAGE_DIR" "$(dirname "$OUTPUT")"

cleanup() {
  rm -rf "$STAGING"
}
trap cleanup EXIT

rsync -a --delete \
  --exclude '.git/' \
  --exclude '_dist/' \
  --exclude 'var/' \
  --exclude '*.sqlite' \
  --exclude '*.sqlite-shm' \
  --exclude '*.sqlite-wal' \
  --exclude '.DS_Store' \
  --exclude '._*' \
  "$ROOT/" "$PACKAGE_DIR/"

chmod +x "$PACKAGE_DIR/bin/portal" "$PACKAGE_DIR/scripts/install.sh" "$PACKAGE_DIR/scripts/package-release.sh"

COMMIT="unknown"
DIRTY="false"
if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
  if [[ -n "$(git -C "$ROOT" status --porcelain)" ]]; then
    DIRTY="true"
  fi
fi

cat > "$PACKAGE_DIR/RELEASE.json" <<JSON
{
  "name": "SesamePortal",
  "version": "$VERSION",
  "sourceCommit": "$COMMIT",
  "dirty": $DIRTY,
  "builtAt": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
}
JSON

COPYFILE_DISABLE=1 tar --no-xattrs -C "$STAGING" -czf "$OUTPUT" sesame-portal

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$OUTPUT" > "$OUTPUT.sha256"
else
  shasum -a 256 "$OUTPUT" > "$OUTPUT.sha256"
fi

echo "artifact: $OUTPUT"
echo "sha256:   $OUTPUT.sha256"
