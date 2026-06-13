#!/bin/bash
# Ticket Report WA — scheduled summary every 2 hours
#
# Evo API URL priority:
#   1. $EVOLUTION_API_BASE_URL (set in .env via Laravel or sourced by crontab)
#   2. $EVO_URL (explicit override)
#   3. localhost:8085 (works on 106 via autossh tunnel 0.0.0.0:8085->102:8085)
#   4. http://103.156.128.102:8085 (public, works from 216)
set -euo pipefail
# Try to source .env from script dir so $EVOLUTION_API_BASE_URL is set
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Preserve overrides from caller (e.g. systemd Environment=)
__EVO_PRESET="${EVOLUTION_API_BASE_URL:-${EVO_URL:-}}"
if [ -f "$SCRIPT_DIR/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  . "$SCRIPT_DIR/.env"
  set +a
fi
# Restore caller overrides — .env is shared with Laravel containers and may
# contain host.docker.internal URLs that don't work from the host itself.
if [ -n "$__EVO_PRESET" ]; then
  EVOLUTION_API_BASE_URL="$__EVO_PRESET"
  EVO_URL="$__EVO_PRESET"
fi

EVO_URL="${EVOLUTION_API_BASE_URL:-${EVO_URL:-http://localhost:8085}}"
EVO_KEY="${EVOLUTION_API_API_KEY:-${EVO_API_KEY:-}}"  # from .env, NEVER hardcode!
EVO_INSTANCE="skynet-ebilling"
GROUP_JID="120363406951510568@g.us"
MYSQL_CONTAINER="skynet-ebilling-mysql-1"
MYSQL_USER="skynet"
MYSQL_PASS="${DB_PASSWORD:-}"  # from .env, NEVER hardcode!
MYSQL_DB="skynet_ebilling"
APP_URL="https://ebilling.sky.net.id"
# Legacy: tinyurl cache path kept for backward compat (no longer used — links are now direct ebilling.sky.net.id URLs)
TINYURL_CACHE="/opt/skynet-ebilling/src/.tinyurl-cache"

mysql_exec() {
    docker exec "$MYSQL_CONTAINER" mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -N -e "$1" 2>/dev/null
}

# Get direct ticket URL (no URL shortener — full ebilling.sky.net.id domain).
# Old behaviour used tinyurl.com shorten + cache; removed so WA group messages
# always show the real domain for trust + clickability in modern WA clients.
get_ticket_url() {
    local tid="$1"
    echo "${APP_URL}/tickets/${tid}"
}

# Kept as alias in case any external caller references the old name
get_short_url() {
    get_ticket_url "$@"
}

NOW=$(TZ=Asia/Jakarta date '+%H:%M')
TODAY=$(TZ=Asia/Jakarta date '+%d/%m/%Y')

# ── Stats ──
STATS=$(mysql_exec "SELECT status, COUNT(*) as total FROM support_tickets GROUP BY status")

OPEN=0; PENDING=0; IN_PROGRESS=0; CLOSED=0; TOTAL=0
while IFS=$'\t' read -r status count; do
    [ -z "$status" ] && continue
    TOTAL=$((TOTAL + count))
    case "$status" in
        open|assigned) OPEN=$((OPEN + count)) ;;
        pending) PENDING=$((PENDING + count)) ;;
        in_progress) IN_PROGRESS=$((IN_PROGRESS + count)) ;;
        closed) CLOSED=$((CLOSED + count)) ;;
    esac
done <<< "$STATS"

OPENED_TODAY=$(mysql_exec "SELECT COUNT(*) FROM support_tickets WHERE created_at >= CURDATE()" | tr -d '\n')
CLOSED_TODAY=$(mysql_exec "SELECT COUNT(*) FROM support_tickets WHERE status='closed' AND updated_at >= CURDATE()" | tr -d '\n')

# ── Open tickets ──
OPEN_LIST=$(mysql_exec "
    SELECT t.id, t.code, IFNULL(t.customer_name,'-'), IFNULL(LEFT(t.gangguan,30),'-'), IFNULL(u.name,'-')
    FROM support_tickets t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.status IN ('open','assigned','in_progress','pending')
    ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.created_at ASC
    LIMIT 15" 2>/dev/null)

# ── Recently Closed ──
CLOSED_LIST=$(mysql_exec "
    SELECT t.id, t.code, IFNULL(t.customer_name,'-'), IFNULL(LEFT(t.gangguan,30),'-'), IFNULL(u.name,'-'), 
           DATE_FORMAT(t.updated_at,'%H:%i')
    FROM support_tickets t
    LEFT JOIN users u ON t.assigned_to = u.id
    WHERE t.status='closed' AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
    ORDER BY t.updated_at DESC
    LIMIT 10" 2>/dev/null)

# ── Build message ──
MSG="📊 *LAPORAN TIKET* — ${TODAY} ${NOW} WIB

🟢 ${OPEN} open  🟡 ${PENDING} pending  🔵 ${IN_PROGRESS} progress  ✅ ${CLOSED} closed
📌 Total: *${TOTAL}* — Hari ini: ${OPENED_TODAY:-0} baru, ${CLOSED_TODAY:-0} closed"

if [ -n "$OPEN_LIST" ]; then
    MSG+="

*🟢 OPEN:*"
    while IFS=$'\t' read -r tid code name gangguan teknisi; do
        [ -z "$tid" ] && continue
        short_url=$(get_short_url "$tid")
        MSG+="
${code} — ${name} • ${teknisi}
${gangguan}
${short_url}"
    done <<< "$OPEN_LIST"
fi

if [ -n "$CLOSED_LIST" ]; then
    MSG+="

*✅ CLOSED 2 JAM TERAKHIR:*"
    while IFS=$'\t' read -r tid code name gangguan teknisi time; do
        [ -z "$tid" ] && continue
        short_url=$(get_short_url "$tid")
        MSG+="
${code} — ${name} • ${teknisi} • ${time}
${gangguan}
${short_url}"
    done <<< "$CLOSED_LIST"
fi

MSG+="

🌐 Buka eBilling: ${APP_URL}

_Skynet eBilling_"

# ── Send ──
PAYLOAD=$(jq -n \
    --arg number "$GROUP_JID" \
    --arg text "$MSG" \
    '{number: $number, text: $text, delay: 1000}')

RESPONSE=$(curl -s -X POST "${EVO_URL}/message/sendText/${EVO_INSTANCE}" \
    -H "apikey: ${EVO_KEY}" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD")

if echo "$RESPONSE" | jq -e '.key.id' > /dev/null 2>&1; then
    echo "[$(date -u '+%Y-%m-%d %H:%M UTC')] Scheduled report sent"
else
    echo "[$(date -u '+%Y-%m-%d %H:%M UTC')] Failed: $RESPONSE" >&2
    exit 1
fi
