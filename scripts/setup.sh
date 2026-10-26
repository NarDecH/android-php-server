#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
STATE_DIR="${PHP_SERVER_STATE_DIR:-$HOME/.php-server}"
CONFIG_FILE="$STATE_DIR/config.env"
FILES_DIR="$ROOT_DIR/www/files"

say() { printf '%s\n' "$*"; }
fail() { printf 'ผิดพลาด: %s\n' "$*" >&2; exit 1; }

say "=== PHP Server on Android — ตั้งค่าเริ่มต้น ==="
say ""

if command -v pkg >/dev/null 2>&1 && [ -n "${PREFIX:-}" ] && [[ "${PREFIX}" == *"com.termux"* ]]; then
    say "[1/3] ตรวจพบ Termux — กำลังติดตั้ง PHP, curl และ unzip"
    if ! pkg install -y php curl unzip; then
        fail "ติดตั้งแพ็กเกจไม่สำเร็จ — ลองรัน 'pkg update -y' แล้วรัน setup อีกครั้ง"
    fi
else
    say "[1/3] ไม่ได้รันใน Termux — ข้ามการติดตั้งแพ็กเกจ (ต้องมี php อยู่แล้ว)"
fi

command -v php >/dev/null 2>&1 || fail "ไม่พบคำสั่ง php — ติดตั้งด้วย 'pkg install php' ก่อน"
say "      PHP $(php -r 'echo PHP_VERSION;')"

say "[2/3] เตรียมโฟลเดอร์เว็บและโฟลเดอร์สถานะ"
mkdir -p "$FILES_DIR" "$STATE_DIR"
touch "$FILES_DIR/.gitkeep"
[ -w "$FILES_DIR" ] || fail "www/files เขียนไม่ได้ — ตรวจสอบสิทธิ์ของโฟลเดอร์โปรเจกต์"
chmod 700 "$STATE_DIR" 2>/dev/null || true

for script in "$SCRIPT_DIR"/*.sh; do
    chmod +x "$script" 2>/dev/null || true
done

if [ ! -f "$CONFIG_FILE" ]; then
    cat > "$CONFIG_FILE" <<'EOF'
# ตั้งค่าของ PHP Server on Android
# แก้ไขแล้วรัน: bash scripts/server.sh restart

HOST=127.0.0.1
PORT=8080
PHP_WORKERS=4
APP_TZ=Asia/Bangkok
MAX_UPLOAD_MB=64

# ตั้ง PIN ก่อนเปิดให้เครื่องอื่นใน Wi-Fi เข้าถึง (ค่าเริ่มต้น: ไม่มี PIN)
UPLOAD_PIN=
EOF
    chmod 600 "$CONFIG_FILE" 2>/dev/null || true
    say "      สร้างไฟล์ตั้งค่า: $CONFIG_FILE"
else
    say "      ใช้ไฟล์ตั้งค่าเดิม: $CONFIG_FILE"
fi

say "[3/3] ตรวจสอบสิทธิ์เข้าถึง Downloads"
if [ -d "$HOME/storage/downloads" ]; then
    say "      พบ ~/storage/downloads แล้ว"
else
    say "      ยังไม่พบ ~/storage/downloads"
    say "      ถ้าต้องการนำเข้าไฟล์จาก Downloads ให้รัน: termux-setup-storage"
fi

say ""
say "พร้อมใช้งาน! ขั้นตอนถัดไป:"
say "  bash scripts/server.sh start"
say "  bash scripts/server.sh open"
say ""
say "หรือเปิดเบราว์เซอร์ที่ http://127.0.0.1:8080"
