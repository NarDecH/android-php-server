#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
STATE_DIR="${PHP_SERVER_STATE_DIR:-$HOME/.php-server}"
CONFIG_FILE="$STATE_DIR/config.env"
BOOT_DIR="$HOME/.termux/boot"
BOOT_FILE="$BOOT_DIR/php-server.sh"

say() { printf '%s\n' "$*"; }
err() { printf 'ผิดพลาด: %s\n' "$*" >&2; }

if [ "${1:-}" = "--remove" ]; then
    rm -f "$BOOT_FILE"
    say "ลบสคริปต์เปิดอัตโนมัติแล้ว: $BOOT_FILE"
    exit 0
fi

if [ ! -f "$CONFIG_FILE" ]; then
    err "ยังไม่มีไฟล์ตั้งค่า — รัน: bash scripts/setup.sh"
    exit 1
fi

command -v php >/dev/null 2>&1 || {
    err "ไม่พบคำสั่ง php — รัน: bash scripts/setup.sh"
    exit 1
}

mkdir -p "$BOOT_DIR" "$STATE_DIR"

cat > "$BOOT_FILE" <<EOF
#!/usr/bin/env bash
# สร้างโดย scripts/install-boot.sh
# ถ้าย้ายโฟลเดอร์โปรเจกต์ ให้รัน install-boot.sh ใหม่

mkdir -p "\$HOME/.php-server"

if command -v termux-wake-lock >/dev/null 2>&1; then
    termux-wake-lock >/dev/null 2>&1 || true
fi

sleep 8
exec bash "$ROOT_DIR/scripts/server.sh" start >> "\$HOME/.php-server/boot.log" 2>&1
EOF

chmod +x "$BOOT_FILE"

say "✓ ติดตั้งสคริปต์เปิดอัตโนมัติแล้ว"
say "  ไฟล์: $BOOT_FILE"
say ""
say "ต้องมี Termux:Boot (จาก F-Droid) และเปิดแอป Termux:Boot อย่างน้อยหนึ่งครั้ง"
say "แนะนำปิด battery optimization ให้ Termux ด้วย"
say "ทดสอบตอนนี้ได้ด้วย: bash \"$BOOT_FILE\""
say "ยกเลิกด้วย: bash scripts/install-boot.sh --remove"
