#!/usr/bin/env bash
# ใช้ค่าตั้งใหม่จาก config.env: รีสตาร์ทเซิร์ฟเวอร์ แล้วย้อนกลับค่าเดิมถ้าไม่ขึ้น
#
# ถูกเรียกจาก settings.php แบบ detached (nohup) เพื่อให้รอดจากการที่
# ตัวเซิร์ฟเวอร์ปัจจุบันถูก kill ระหว่างรีสตาร์ท
#
# ใช้งานเอง: bash scripts/apply-settings.sh [พอร์ตใหม่]
set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
STATE_DIR="${PHP_SERVER_STATE_DIR:-$HOME/.php-server}"
CONFIG_FILE="$STATE_DIR/config.env"
BACKUP_FILE="$STATE_DIR/config.env.bak"
RESULT_FILE="$STATE_DIR/settings-result.txt"
LOG_FILE="$STATE_DIR/settings.log"
NEW_PORT="${1:-}"

mkdir -p "$STATE_DIR"

# ปิด file descriptor ที่สืบทอดมาจาก PHP (รวม listening socket ของเซิร์ฟเวอร์)
# ถ้าไม่ปิด สคริปต์นี้จะจองพอร์ตค้างไว้ ทำให้ "server.sh restart" bind พอร์ตเดิมไม่ได้
for _fd in $(seq 3 64); do
    { eval "exec ${_fd}>&-"; } 2>/dev/null || true
done
unset _fd

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*" >>"$LOG_FILE"; }
result() { printf '%s\n' "$*" >"$RESULT_FILE"; }
health_ok() { curl -fsS -m 2 "http://127.0.0.1:$1/healthz" >/dev/null 2>&1; }

log "เริ่มใช้ค่าตั้งใหม่ (พอร์ต ${NEW_PORT:-ไม่ระบุ})"

# ให้ HTTP response ของหน้าตั้งค่ากลับไปก่อน แล้วค่อยรีสตาร์ต
sleep 2

bash "$ROOT_DIR/scripts/server.sh" restart >>"$LOG_FILE" 2>&1

if [ -n "$NEW_PORT" ]; then
    for _ in $(seq 1 25); do
        if health_ok "$NEW_PORT"; then
            result "ok|$NEW_PORT|$(date '+%F %T')"
            log "สำเร็จ: พอร์ต $NEW_PORT ตอบ /healthz"
            exit 0
        fi
        sleep 1
    done
fi

log "ไม่ตอบสนองที่พอร์ต ${NEW_PORT:-?} — เริ่มย้อนกลับ"

if [ -f "$BACKUP_FILE" ]; then
    cp "$BACKUP_FILE" "$CONFIG_FILE"
    chmod 600 "$CONFIG_FILE" 2>/dev/null || true
    OLD_PORT="$(grep -E '^PORT=' "$BACKUP_FILE" | tail -n 1 | cut -d= -f2 | tr -d '[:space:]"'"'"'')"

    bash "$ROOT_DIR/scripts/server.sh" restart >>"$LOG_FILE" 2>&1

    if [ -n "$OLD_PORT" ] && health_ok "$OLD_PORT"; then
        result "rollback|$OLD_PORT|$(date '+%F %T')"
        log "ย้อนกลับสำเร็จ: พอร์ต $OLD_PORT ทำงานตามเดิม"
        exit 1
    fi
fi

result "fail|-|$(date '+%F %T')"
log "ย้อนกลับไม่สำเร็จ — ต้องสั่ง start เองใน Termux"
exit 2
