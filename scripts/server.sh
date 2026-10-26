#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
STATE_DIR="${PHP_SERVER_STATE_DIR:-$HOME/.php-server}"
CONFIG_FILE="$STATE_DIR/config.env"
PID_FILE="$STATE_DIR/server.pid"
LOG_FILE="$STATE_DIR/server.log"
PHP_ERROR_LOG="$STATE_DIR/php-error.log"

HOST="127.0.0.1"
PORT="8080"
PHP_WORKERS="4"
APP_TZ="Asia/Bangkok"
MAX_UPLOAD_MB="64"
UPLOAD_PIN=""

say() { printf '%s\n' "$*"; }
err() { printf 'ผิดพลาด: %s\n' "$*" >&2; }

usage() {
    cat <<'EOF'
ใช้งาน: bash scripts/server.sh <คำสั่ง>

คำสั่ง:
  start     เริ่ม PHP server แบบเบื้องหลัง
  stop      หยุดเซิร์ฟเวอร์
  restart   หยุดแล้วเริ่มใหม่ (ใช้หลังแก้ config)
  status    แสดงสถานะ, PID, URL และ health check
  logs      แสดง log ล่าสุด 80 บรรทัด
  open      เปิด URL ในเบราว์เซอร์ของ Android
  url       พิมพ์ URL ของเซิร์ฟเวอร์
  config    แสดงค่าตั้งค่า (ซ่อน PIN)
  help      แสดงข้อความนี้

ไฟล์ตั้งค่า: ~/.php-server/config.env
EOF
}

if [ ! -f "$CONFIG_FILE" ]; then
    err "ยังไม่มีไฟล์ตั้งค่า — รัน: bash scripts/setup.sh"
    exit 1
fi

# shellcheck disable=SC1090
set -a
. "$CONFIG_FILE"
set +a

case "$HOST" in
    127.0.0.1|0.0.0.0) ;;
    *)
        err "HOST ต้องเป็น 127.0.0.1 หรือ 0.0.0.0 (พบ: $HOST)"
        exit 1
        ;;
esac

if ! [[ "$PORT" =~ ^[0-9]+$ ]] || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    err "PORT ไม่ถูกต้อง: $PORT"
    exit 1
fi

if ! [[ "$PHP_WORKERS" =~ ^[0-9]+$ ]] || [ "$PHP_WORKERS" -lt 1 ] || [ "$PHP_WORKERS" -gt 32 ]; then
    PHP_WORKERS=4
fi

if ! [[ "$MAX_UPLOAD_MB" =~ ^[0-9]+$ ]] || [ "$MAX_UPLOAD_MB" -lt 1 ] || [ "$MAX_UPLOAD_MB" -gt 512 ]; then
    MAX_UPLOAD_MB=64
fi

URL_HOST="$HOST"
[ "$HOST" = "0.0.0.0" ] && URL_HOST="127.0.0.1"
BASE_URL="http://${URL_HOST}:${PORT}"

PHP_ARGS=(
    -d "upload_max_filesize=${MAX_UPLOAD_MB}M"
    -d "post_max_size=$((MAX_UPLOAD_MB + 4))M"
    -d "memory_limit=256M"
    -d "max_execution_time=120"
    -d "display_errors=0"
    -d "display_startup_errors=0"
    -d "log_errors=1"
    -d "error_log=${PHP_ERROR_LOG}"
    -d "date.timezone=${APP_TZ}"
    -d "expose_php=0"
)

require_php() {
    command -v php >/dev/null 2>&1 || {
        err "ไม่พบคำสั่ง php — รัน: bash scripts/setup.sh"
        return 1
    }
}

get_pid() {
    cat "$PID_FILE" 2>/dev/null || true
}

is_running() {
    [ -f "$PID_FILE" ] || return 1

    local pid
    pid="$(get_pid)"
    [[ "$pid" =~ ^[0-9]+$ ]] || return 1
    kill -0 "$pid" 2>/dev/null || return 1

    # กัน PID ถูกนำไปใช้ซ้ำโดยโปรเซสอื่น
    local cmdline
    if cmdline="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)"; then
        case "$cmdline" in
            *router.php*) ;;
            *) return 1 ;;
        esac
    fi

    return 0
}

check_port() {
    php -r '
        $host = $argv[1];
        $port = (int) $argv[2];
        $errno = 0;
        $errstr = "";
        $socket = @stream_socket_server("tcp://" . $host . ":" . $port, $errno, $errstr);
        if ($socket === false) {
            exit(1);
        }
        fclose($socket);
    ' "$HOST" "$PORT" >/dev/null 2>&1
}

health_ok() {
    php -r '
        $socket = @fsockopen("127.0.0.1", (int) $argv[1], $errno, $errstr, 0.8);
        if ($socket === false) {
            exit(1);
        }
        stream_set_timeout($socket, 1);
        fwrite($socket, "GET /healthz HTTP/1.0\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);
        exit(str_contains($response, "200 OK") && str_contains($response, "ok") ? 0 : 1);
    ' "$PORT" >/dev/null 2>&1
}

wait_ready() {
    local pid="$1"
    local attempt=0

    while [ "$attempt" -lt 60 ]; do
        kill -0 "$pid" 2>/dev/null || return 1
        health_ok && return 0
        sleep 0.2
        attempt=$((attempt + 1))
    done

    return 1
}

wake_lock() {
    if command -v termux-wake-lock >/dev/null 2>&1; then
        termux-wake-lock >/dev/null 2>&1 || true
    fi
}

wake_unlock() {
    if command -v termux-wake-unlock >/dev/null 2>&1; then
        termux-wake-unlock >/dev/null 2>&1 || true
    fi
}

start_server() {
    require_php || exit 1
    mkdir -p "$STATE_DIR" "$ROOT_DIR/www/files" || {
        err "เตรียมโฟลเดอร์สถานะไม่สำเร็จ"
        exit 1
    }

    if is_running; then
        say "เซิร์ฟเวอร์ทำงานอยู่แล้ว (PID $(get_pid))"
        say "เปิด: $BASE_URL"
        return 0
    fi

    rm -f "$PID_FILE"

    if ! check_port; then
        err "พอร์ต $PORT ถูกใช้งานอยู่ หรือไม่มีสิทธิ์ bind"
        say "แก้ไข PORT ได้ที่: $CONFIG_FILE"
        exit 1
    fi

    say "กำลังเริ่ม PHP server ที่พอร์ต $PORT…"

    (
        cd "$ROOT_DIR" || exit 1
        export APP_TZ UPLOAD_PIN
        if [ "$PHP_WORKERS" -gt 1 ]; then
            export PHP_CLI_SERVER_WORKERS="$PHP_WORKERS"
        fi
        nohup php "${PHP_ARGS[@]}" \
            -S "${HOST}:${PORT}" \
            -t "$ROOT_DIR/www" \
            "$ROOT_DIR/router.php" \
            </dev/null >>"$LOG_FILE" 2>&1 &
        echo $! >"$PID_FILE"
    )

    local pid
    pid="$(get_pid)"

    if [ -n "$pid" ] && wait_ready "$pid"; then
        wake_lock
        say "✓ เริ่มทำงานแล้ว (PID $pid)"
        say "  เปิดเบราว์เซอร์: $BASE_URL"

        if [ "$HOST" = "0.0.0.0" ]; then
            say ""
            say "โหมด LAN: เปิดจากเครื่องอื่นได้ที่ http://<IP-ของมือถือ>:${PORT}"
            if [ -z "$UPLOAD_PIN" ]; then
                say "คำเตือน: ยังไม่ได้ตั้ง UPLOAD_PIN — ควรตั้งก่อนให้เครื่องอื่นเข้าถึง"
            fi
        else
            say "  ฟังเฉพาะเครื่องนี้ (127.0.0.1) — เครื่องอื่นเข้าถึงไม่ได้"
        fi

        say "  หยุดเซิร์ฟเวอร์: bash scripts/server.sh stop"
        return 0
    fi

    err "เริ่มเซิร์ฟเวอร์ไม่สำเร็จ"
    say "--- log ล่าสุด ---"
    tail -n 25 "$LOG_FILE" 2>/dev/null || true

    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        kill "$pid" 2>/dev/null || true
    fi
    rm -f "$PID_FILE"
    exit 1
}

stop_server() {
    if ! is_running; then
        rm -f "$PID_FILE"
        say "เซิร์ฟเวอร์ไม่ได้ทำงานอยู่"
        wake_unlock
        return 0
    fi

    local pid children=""
    pid="$(get_pid)"

    if [ -r "/proc/$pid/task/$pid/children" ]; then
        children="$(cat "/proc/$pid/task/$pid/children" 2>/dev/null || true)"
    fi

    say "กำลังหยุดเซิร์ฟเวอร์ (PID $pid)…"
    kill "$pid" 2>/dev/null || true

    local attempt=0
    while kill -0 "$pid" 2>/dev/null && [ "$attempt" -lt 20 ]; do
        sleep 0.25
        attempt=$((attempt + 1))
    done

    if kill -0 "$pid" 2>/dev/null; then
        say "เซิร์ฟเวอร์ยังไม่หยุด — บังคับปิด"
        kill -9 "$pid" 2>/dev/null || true
    fi

    local child
    for child in $children; do
        kill "$child" 2>/dev/null || true
    done

    rm -f "$PID_FILE"
    wake_unlock
    say "✓ หยุดเซิร์ฟเวอร์แล้ว"
}

show_status() {
    require_php || return 1

    if is_running; then
        say "สถานะ : กำลังทำงาน"
        say "PID    : $(get_pid)"
        say "URL    : $BASE_URL"
        if health_ok; then
            say "Health : ตอบสนองปกติ (/healthz)"
        else
            say "Health : ไม่ตอบสนอง — ดู log ด้วย bash scripts/server.sh logs"
        fi
        say "Config : $CONFIG_FILE"
        say "Log    : $LOG_FILE"
    else
        say "สถานะ : หยุดอยู่"
        say "เริ่ม   : bash scripts/server.sh start"
        say "Config : $CONFIG_FILE"
    fi
}

show_logs() {
    if [ ! -f "$LOG_FILE" ]; then
        say "ยังไม่มี log — เริ่มเซิร์ฟเวอร์ก่อน"
        return 0
    fi

    tail -n 80 "$LOG_FILE"
}

open_browser() {
    if ! is_running; then
        err "เซิร์ฟเวอร์ยังไม่ทำงาน — รัน: bash scripts/server.sh start"
        return 1
    fi

    say "เปิด: $BASE_URL"

    if command -v termux-open-url >/dev/null 2>&1; then
        termux-open-url "$BASE_URL"
    elif command -v am >/dev/null 2>&1; then
        am start -a android.intent.action.VIEW -d "$BASE_URL" >/dev/null 2>&1 || true
    elif command -v xdg-open >/dev/null 2>&1; then
        xdg-open "$BASE_URL" >/dev/null 2>&1 || true
    else
        say "เปิดเบราว์เซอร์ด้วยตนเองที่ URL ด้านบน"
    fi
}

show_config() {
    say "ไฟล์ตั้งค่า: $CONFIG_FILE"
    say "HOST=$HOST"
    say "PORT=$PORT"
    say "PHP_WORKERS=$PHP_WORKERS"
    say "APP_TZ=$APP_TZ"
    say "MAX_UPLOAD_MB=$MAX_UPLOAD_MB"
    if [ -n "$UPLOAD_PIN" ]; then
        say "UPLOAD_PIN=****** (ตั้งไว้แล้ว)"
    else
        say "UPLOAD_PIN=(ว่าง)"
    fi
}

COMMAND="${1:-help}"

case "$COMMAND" in
    start)
        start_server
        ;;
    stop)
        stop_server
        ;;
    restart)
        stop_server
        start_server
        ;;
    status)
        show_status
        ;;
    logs)
        show_logs
        ;;
    open)
        open_browser
        ;;
    url)
        say "$BASE_URL"
        ;;
    config)
        show_config
        ;;
    help|-h|--help)
        usage
        ;;
    *)
        err "ไม่รู้จักคำสั่ง: $COMMAND"
        usage
        exit 1
        ;;
esac
