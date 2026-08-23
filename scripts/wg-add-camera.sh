#!/usr/bin/env bash
# ============================================================================
# wg-add-camera.sh — add an OpenIPC camera as a WireGuard peer of a SesameDVR
# server, so the DVR can pull the camera's RTSP stream over the tunnel.
#
# PURPOSE
#   On OpenIPC cameras behind a LAN/NAT, the DVR (a remote public server) cannot
#   reach the camera directly. This script establishes a WireGuard tunnel: the
#   camera connects outward to the DVR (which runs a WG server), receives a
#   tunnel IP (e.g. 10.250.0.x), and the DVR then pulls RTSP from that tunnel IP.
#
# TOPOLOGY
#   camera (LAN, e.g. 192.168.2.122)  ──WG──  DVR (public, e.g. onvif.mlzone.ru)
#                                          wg0: 10.250.0.1/22, ListenPort 51820
#   camera gets 10.250.0.x/22, peer = DVR server pubkey, Endpoint = DVR:51820,
#   PersistentKeepalive=25 (keeps NAT mapping alive so the DVR can reach back).
#
# RUN FROM
#   Any host that can SSH to BOTH the camera (password auth) and the DVR (key
#   auth, possibly passphrase-protected). Typically the SesamePortal host on
#   the same LAN as the camera. No WireGuard tools required on the running host.
#
# PREREQUISITES
#   - sshpass, ssh-agent, python3 on the running host.
#   - Camera: OpenIPC firmware with wireguard kernel module (modprobe wireguard)
#     and the `wg` userspace tool. SSH root access with a password.
#   - DVR: WireGuard already configured and wg0 up; /etc/wireguard/server.pub
#     present; the running host's SSH key authorised (or passphrase provided).
#
# WHAT THE SCRIPT DOES (in order)
#   1. Reads DVR WG state (server pubkey, existing peers) and checks the chosen
#      tunnel IP is free.
#   2. On the camera: generates private/public/preshared keys in /etc/wireguard/.
#   3. On the camera: writes wg0.conf (full) and wg0.peer.conf (for wg setconf).
#   4. On the camera: brings up wg0 (modprobe + ip link add + wg setconf + ip
#      addr + ip link up), idempotent.
#   5. On the camera: installs autostart in /etc/rc.local.
#   6. On the DVR: adds the peer live (wg set, no downtime) with the camera's
#      pubkey, PSK, and AllowedIPs = <tunnel-ip>/32.
#   7. On the DVR: appends a [Peer] block to /etc/wireguard/wg0.conf for
#      persistence across reboots.
#   8. Verifies handshake, ping both ways, and RTSP port reachability from DVR.
#   9. Prints the RTSP source URL to paste into SesamePortal /admin/cameras.
#
# USAGE
#   bash scripts/wg-add-camera.sh \
#       --camera-ip 192.168.2.122 \
#       --camera-ssh-pass efd1loma \
#       --tunnel-ip 10.250.0.3 \
#       --rtsp-user video --rtsp-pass uraCh3hu --rtsp-path video0
#
#   bash scripts/wg-add-camera.sh --help        # full options
#   bash scripts/wg-add-camera.sh --dry-run ...  # print plan, change nothing
#   bash scripts/wg-add-camera.sh --force ...    # regenerate camera keys
#
# ENV OVERRIDES (DVR access)
#   DVR_HOST, DVR_SSH_USER, DVR_PORT, DVR_WG_IFACE, DVR_SSH_PASSPHRASE
#
# NOTES
#   - Camera keys/PSK are generated ON the camera (never leave it).
#   - The PSK is copied to the DVR only to register the peer. Passphrases and
#     passwords are not logged.
#   - After the script succeeds, add the camera in SesamePortal with mode
#     "Полное управление на DVR" (managed) and the printed source URL.
# ============================================================================

set -euo pipefail

# ---- defaults ---------------------------------------------------------------
CAMERA_IP=""
CAMERA_SSH_USER="root"
CAMERA_SSH_PASS=""
TUNNEL_IP=""
TUNNEL_PREFIX="22"
CAMERA_NAME=""
RTSP_USER=""
RTSP_PASS=""
RTSP_PATH=""
RTSP_PORT="554"

DVR_HOST="${DVR_HOST:-onvif.mlzone.ru}"
DVR_SSH_USER="${DVR_SSH_USER:-root}"
DVR_PORT="${DVR_PORT:-51820}"
DVR_WG_IFACE="${DVR_WG_IFACE:-wg0}"
DVR_PASSPHRASE="${DVR_SSH_PASSPHRASE:-}"

DRY_RUN=0
FORCE=0

# ---- helpers ----------------------------------------------------------------
usage() {
  cat <<'USAGE'
Usage:
  bash scripts/wg-add-camera.sh --camera-ip <ip> --camera-ssh-pass <pass> --tunnel-ip <ip> [options]

Required:
  --camera-ip <ip>            Camera LAN address (e.g. 192.168.2.122).
  --camera-ssh-pass <pass>    SSH password for the camera (root).
  --tunnel-ip <ip>            Camera address inside the WG tunnel (e.g. 10.250.0.3).

Camera options:
  --camera-ssh-user <user>    SSH login, default root.
  --camera-name <name>        Peer comment on the DVR, default "Camera <tunnel-ip> - <camera-ip>".
  --rtsp-user <user>          RTSP username, used only to print the portal source URL.
  --rtsp-pass <pass>          RTSP password, used only to print the portal source URL.
  --rtsp-path <path>          RTSP stream path (e.g. video0), used only to print the URL.
  --rtsp-port <port>          RTSP port, default 554.
  --tunnel-prefix <len>       Tunnel subnet prefix length, default 22 (10.250.0.0/22).

DVR options (env overrides: DVR_HOST, DVR_SSH_USER, DVR_PORT, DVR_WG_IFACE, DVR_SSH_PASSPHRASE):
  --dvr-host <host>           DVR host, default onvif.mlzone.ru.
  --dvr-ssh-user <user>       DVR SSH user, default root.
  --dvr-port <port>           DVR WG ListenPort, default 51820.
  --dvr-wg-iface <name>       DVR WG interface, default wg0.
  --dvr-passphrase <pass>     Passphrase for the local SSH key used to reach the DVR.

Other:
  --dry-run                   Print commands without executing.
  --force                     Regenerate camera keys even if present.
  -h, --help                  Show this help.
USAGE
}

log()  { echo "[wg-add-camera] $*"; }
err()  { echo "[wg-add-camera] ERROR: $*" >&2; }
die()  { err "$*"; exit 1; }

run()  { if [[ $DRY_RUN -eq 1 ]]; then echo "+ $*"; else "$@"; fi; }

# ---- args -------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --camera-ip)         CAMERA_IP="${2:-}"; shift 2 ;;
    --camera-ssh-user)   CAMERA_SSH_USER="${2:-}"; shift 2 ;;
    --camera-ssh-pass)   CAMERA_SSH_PASS="${2:-}"; shift 2 ;;
    --camera-name)       CAMERA_NAME="${2:-}"; shift 2 ;;
    --tunnel-ip)         TUNNEL_IP="${2:-}"; shift 2 ;;
    --tunnel-prefix)     TUNNEL_PREFIX="${2:-}"; shift 2 ;;
    --rtsp-user)         RTSP_USER="${2:-}"; shift 2 ;;
    --rtsp-pass)         RTSP_PASS="${2:-}"; shift 2 ;;
    --rtsp-path)         RTSP_PATH="${2:-}"; shift 2 ;;
    --rtsp-port)         RTSP_PORT="${2:-}"; shift 2 ;;
    --dvr-host)          DVR_HOST="${2:-}"; shift 2 ;;
    --dvr-ssh-user)      DVR_SSH_USER="${2:-}"; shift 2 ;;
    --dvr-port)          DVR_PORT="${2:-}"; shift 2 ;;
    --dvr-wg-iface)      DVR_WG_IFACE="${2:-}"; shift 2 ;;
    --dvr-passphrase)    DVR_PASSPHRASE="${2:-}"; shift 2 ;;
    --dry-run)           DRY_RUN=1; shift ;;
    --force)             FORCE=1; shift ;;
    -h|--help)           usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
  esac
done

# ---- validation -------------------------------------------------------------
[[ -n "$CAMERA_IP" ]]   || { usage; die "--camera-ip is required"; }
[[ -n "$CAMERA_SSH_PASS" ]] || { usage; die "--camera-ssh-pass is required"; }
[[ -n "$TUNNEL_IP" ]]   || { usage; die "--tunnel-ip is required"; }

# tunnel-ip sanity: not network/broadcast, not the server (.1 by convention).
case "$TUNNEL_IP" in
  *.0|*.255) die "Invalid tunnel-ip (network/broadcast): $TUNNEL_IP" ;;
esac
[[ "$TUNNEL_IP" != *.1 ]] || die "Tunnel-ip ending in .1 is reserved for the DVR server."

[[ "$TUNNEL_PREFIX" =~ ^[0-9]{1,2}$ ]] || die "--tunnel-prefix must be a number (e.g. 22)."
TUNNEL_CIDR="${TUNNEL_IP}/${TUNNEL_PREFIX}"
# tunnel network for AllowedIPs on the camera side
TUNNEL_NET=$(python3 - <<PY 2>/dev/null || echo ""
import ipaddress
n = ipaddress.ip_network("${TUNNEL_CIDR}", strict=False)
print(str(n))
PY
)
[[ -n "$TUNNEL_NET" ]] || TUNNEL_NET="10.250.0.0/${TUNNEL_PREFIX}"

[[ -n "$CAMERA_NAME" ]] || CAMERA_NAME="Camera ${TUNNEL_IP} - ${CAMERA_IP}"

command -v sshpass >/dev/null || die "sshpass is required (apt install sshpass)."
command -v ssh-agent >/dev/null || die "ssh-agent is required."
command -v python3 >/dev/null || die "python3 is required (to compute the tunnel network)."

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=8 -o LogLevel=ERROR)
CAM_SSH=(sshpass -p "$CAMERA_SSH_PASS" ssh "${SSH_OPTS[@]}")

# ---- DVR access via ssh-agent (passphrase key) ------------------------------
DVR_AGENT_STARTED=0
setup_dvr_ssh() {
  # If an agent already has the key, reuse it.
  if [[ -n "${SSH_AUTH_SOCK:-}" ]] && ssh-add -l >/dev/null 2>&1; then
    log "Reusing existing ssh-agent ($SSH_AUTH_SOCK)"
    return 0
  fi
  if [[ -z "$DVR_PASSPHRASE" ]]; then
    die "DVR SSH needs a passphrase-protected key. Provide --dvr-passphrase or set DVR_SSH_PASSPHRASE, or pre-load ssh-agent."
  fi
  eval "$(ssh-agent -s)" >/dev/null 2>&1
  DVR_AGENT_STARTED=1
  printf '%s\n' "$DVR_PASSPHRASE" | ssh-add "$HOME/.ssh/id_rsa" >/dev/null 2>&1 || \
    die "Failed to add key to ssh-agent (wrong passphrase or missing ~/.ssh/id_rsa)."
  export SSH_AUTH_SOCK SSH_AGENT_PID
}

cleanup() {
  if [[ $DVR_AGENT_STARTED -eq 1 && -n "${SSH_AGENT_PID:-}" ]]; then
    kill "$SSH_AGENT_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

dvr_ssh() {
  ssh "${SSH_OPTS[@]}" "${DVR_SSH_USER}@${DVR_HOST}" "$@"
}

# ---- preflight on DVR -------------------------------------------------------
setup_dvr_ssh

log "Reading DVR WG state (${DVR_HOST})..."
DVR_STATE=$(dvr_ssh "echo PUB=\$(cat /etc/wireguard/server.pub); echo WG_SHOW_START; wg show ${DVR_WG_IFACE}" 2>&1) \
  || die "Cannot reach DVR or read WG state."
DVR_PUB=$(printf '%s\n' "$DVR_STATE" | sed -n 's/^PUB=//p' | head -1)
[[ -n "$DVR_PUB" ]] || die "Cannot read server public key on DVR (/etc/wireguard/server.pub)."

# Check tunnel-ip is not already used by an existing peer.
EXISTING=$(printf '%s\n' "$DVR_STATE" | awk '/allowed ips:/ {print $3}')
if printf '%s\n' "$EXISTING" | grep -qE "^${TUNNEL_IP}/"; then
  die "Tunnel IP ${TUNNEL_IP} already used by a peer on the DVR. Choose another or remove that peer."
fi
log "DVR server public key: $DVR_PUB"
log "DVR tunnel IP free:    $TUNNEL_IP (no collision in $(printf '%s\n' "$EXISTING" | wc -l) peers)"

if [[ $DRY_RUN -eq 1 ]]; then
  log "Dry-run: would generate keys on ${CAMERA_IP}, write configs, bring up wg0,"
  log "         add peer ${TUNNEL_IP}/32 on DVR, persist in wg0.conf, and verify."
  if [[ -n "$RTSP_USER" && -n "$RTSP_PASS" && -n "$RTSP_PATH" ]]; then
    log "         Source URL: rtsp://${RTSP_USER}:***@${TUNNEL_IP}:${RTSP_PORT}/${RTSP_PATH}"
  fi
  exit 0
fi

# ---- camera: generate keys --------------------------------------------------
log "Connecting to camera ${CAMERA_IP} (root)..."
run "${CAM_SSH[@]}" "test -e /etc/wireguard/priv.key" 2>/dev/null && HAVE_KEYS=1 || HAVE_KEYS=0

GEN_KEYS='mkdir -p /etc/wireguard && cd /etc/wireguard'
if [[ $HAVE_KEYS -eq 1 && $FORCE -eq 0 ]]; then
  log "Camera keys already exist (use --force to regenerate). Reusing."
else
  GEN_KEYS+='; wg genkey | tee priv.key | wg pubkey > pub.key; wg genpsk > psk.key; chmod 600 priv.key psk.key'
fi
GEN_KEYS+='; echo PUB=$(cat pub.key); echo PSK=$(cat psk.key)'

CAM_KEYS=$(run "${CAM_SSH[@]}" "$GEN_KEYS" 2>&1) || die "Failed to generate/read keys on camera."
CAM_PUB=$(printf '%s\n' "$CAM_KEYS" | sed -n 's/^PUB=//p' | head -1)
CAM_PSK=$(printf '%s\n' "$CAM_KEYS" | sed -n 's/^PSK=//p' | head -1)
[[ -n "$CAM_PUB" && -n "$CAM_PSK" ]] || die "Could not read camera pub/psk."

log "Camera public key:     $CAM_PUB"

# ---- camera: write configs --------------------------------------------------
write_cam_conf() {
  run "${CAM_SSH[@]}" "cd /etc/wireguard
cat > wg0.peer.conf <<CONF
[Interface]
PrivateKey = \$(cat priv.key)

[Peer]
PublicKey = ${DVR_PUB}
PresharedKey = \$(cat psk.key)
Endpoint = ${DVR_HOST}:${DVR_PORT}
AllowedIPs = ${TUNNEL_NET}
PersistentKeepalive = 25
CONF
chmod 600 wg0.peer.conf
cat > wg0.conf <<CONF
[Interface]
PrivateKey = \$(cat priv.key)
Address = ${TUNNEL_CIDR}

[Peer]
PublicKey = ${DVR_PUB}
PresharedKey = \$(cat psk.key)
Endpoint = ${DVR_HOST}:${DVR_PORT}
AllowedIPs = ${TUNNEL_NET}
PersistentKeepalive = 25
CONF
chmod 600 wg0.conf
echo CAM_CONF_OK"
}
write_cam_conf >/dev/null || die "Failed to write WG config on camera."

# ---- camera: bring up wg0 (idempotent) -------------------------------------
# OpenIPC has no wg-quick; use modprobe + ip link add + wg setconf.
bring_up_cam() {
  run "${CAM_SSH[@]}" "modprobe wireguard 2>/dev/null || true
# remove stale interface if present (e.g. wrong config from a previous run)
if ip link show wg0 >/dev/null 2>&1; then ip link del wg0 2>/dev/null || true; fi
ip link add dev wg0 type wireguard
wg setconf wg0 /etc/wireguard/wg0.peer.conf
ip addr add ${TUNNEL_CIDR} dev wg0 2>/dev/null || true
ip link set wg0 up
echo CAM_UP_OK"
}
bring_up_cam >/dev/null || die "Failed to bring up wg0 on camera."

# ---- camera: autostart via /etc/rc.local -----------------------------------
# Preserve any existing non-default content, then ensure wg0 block + exit 0.
setup_rclocal() {
  run "${CAM_SSH[@]}" 'if [ ! -f /etc/rc.local ]; then touch /etc/rc.local; fi
if ! grep -q "wg setconf wg0" /etc/rc.local 2>/dev/null; then
  # strip a trailing bare "exit 0" so we can re-add our block first
  sed -i "/^exit 0/d" /etc/rc.local 2>/dev/null || true
  cat >> /etc/rc.local <<"RC"
modprobe wireguard 2>/dev/null || true
ip link add dev wg0 type wireguard 2>/dev/null || true
wg setconf wg0 /etc/wireguard/wg0.peer.conf
ip addr add '"${TUNNEL_CIDR}"' dev wg0 2>/dev/null || true
ip link set wg0 up

exit 0
RC
fi
chmod +x /etc/rc.local
echo RCLOCAL_OK'
}
setup_rclocal >/dev/null || die "Failed to update /etc/rc.local on camera."

# ---- DVR: add peer (live, no downtime) -------------------------------------
# wg set accepts preshared-key as a file path; write psk to a temp file on DVR.
add_dvr_peer() {
  dvr_ssh "psk=\$(mktemp); chmod 600 \$psk
printf '%s' '${CAM_PSK}' > \$psk
wg set ${DVR_WG_IFACE} peer ${CAM_PUB} preshared-key \$psk allowed-ips ${TUNNEL_IP}/32
rm -f \$psk
echo DVR_PEER_LIVE_OK"
}
add_dvr_peer >/dev/null || die "Failed to add peer live on DVR."

# ---- DVR: persist peer in wg0.conf ----------------------------------------
persist_dvr_peer() {
  dvr_ssh "grep -q '${CAM_PUB}' /etc/wireguard/wg0.conf 2>/dev/null || cat >> /etc/wireguard/wg0.conf <<CONF

[Peer]
# ${CAMERA_NAME}
PublicKey = ${CAM_PUB}
PresharedKey = ${CAM_PSK}
AllowedIPs = ${TUNNEL_IP}/32
CONF
echo DVR_PEER_PERSIST_OK"
}
persist_dvr_peer >/dev/null || die "Failed to persist peer in wg0.conf on DVR."

# ---- verify -----------------------------------------------------------------
log "Waiting for handshake..."
sleep 4

CAM_HS=$(run "${CAM_SSH[@]}" "wg show wg0 2>/dev/null | awk '/latest handshake/ {print \$0}'" 2>&1 || true)
log "Camera wg0: ${CAM_HS:-no handshake yet}"

CAM_PING=$(run "${CAM_SSH[@]}" "ping -c 2 -W 2 ${TUNNEL_NET%%/*} 2>&1 | tail -1" 2>&1 || true)
log "Camera -> DVR (${TUNNEL_NET%%/*}): ${CAM_PING}"

DVR_PING=$(dvr_ssh "ping -c 2 -W 2 ${TUNNEL_IP} 2>&1 | tail -1" 2>&1 || true)
log "DVR -> camera (${TUNNEL_IP}): ${DVR_PING}"

DVR_554=$(dvr_ssh "timeout 3 bash -c 'echo > /dev/tcp/${TUNNEL_IP}/554' 2>/dev/null && echo OPEN || echo CLOSED" 2>&1 || echo CLOSED)
log "DVR -> camera RTSP port 554: ${DVR_554}"

# ---- summary ----------------------------------------------------------------
echo
echo "================ WireGuard camera added ================"
echo "Camera LAN:      ${CAMERA_IP}"
echo "Camera tunnel:   ${TUNNEL_CIDR}"
echo "DVR host:        ${DVR_HOST}:${DVR_PORT}  (${DVR_PUB})"
echo "Peer persisted:  /etc/wireguard/wg0.conf  +  live wg set"
echo "Camera files:    /etc/wireguard/{wg0.conf,wg0.peer.conf,priv.key,pub.key,psk.key}"
echo "Autostart:        /etc/rc.local"

if [[ -n "$RTSP_USER" && -n "$RTSP_PASS" && -n "$RTSP_PATH" ]]; then
  URL="rtsp://${RTSP_USER}:${RTSP_PASS}@${TUNNEL_IP}:${RTSP_PORT}/${RTSP_PATH}"
  echo
  echo "Add this camera in SesamePortal (/admin/cameras):"
  echo "  Mode:    Полное управление на DVR (managed)"
  echo "  Server:  ${DVR_HOST}"
  echo "  Source:  ${URL}"
fi
echo "======================================================="