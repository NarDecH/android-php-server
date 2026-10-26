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

BOOT_SHELL="${PREFIX:-/data/data/com.termux/files/usr}/bin/bash"

cat > "$BOOT_FILE" <<EOF
#!$BOOT_SHELL
# สร้างโดย scripts/install-boot.sh
# ถ้าย้ายโฟลเดอร์โปรเจกต์ ให้รัน install-boot.sh ใหม่

export HOME="\${HOME:-/data/data/com.termux/files/home}"
export PREFIX="\${PREFIX:-/data/data/com.termux/files/usr}"
export PATH="\$PREFIX/bin:/data/data/com.termux/files/usr/bin:\${PATH:-/system/bin}"

mkdir -p "\$HOME/.php-server"

if command -v termux-wake-lock >/dev/null 2>&1; then
    termux-wake-lock >/dev/null 2>&1 || true
fi

# เครื่องบางรุ่นบูตช้า/ระบบยังไม่พร้อม ให้ลองใหม่แทนการยอมแพ้ครั้งเดียว
sleep 8
for attempt in 1 2 3; do
    printf '\n[%s] boot attempt %s\n' "\$(date '+%Y-%m-%d %H:%M:%S')" "\$attempt" >> "\$HOME/.php-server/boot.log"
    if bash "$ROOT_DIR/scripts/server.sh" start >> "\$HOME/.php-server/boot.log" 2>&1; then
        exit 0
    fi
    sleep 10
done

exit 1
EOF

chmod +x "$BOOT_FILE"

say "✓ ติดตั้งสคริปต์เปิดอัตโนมัติแล้ว"
say "  ไฟล์: $BOOT_FILE"
say ""
say "ต้องมี Termux:Boot (จาก F-Droid) และเปิดแอป Termux:Boot อย่างน้อยหนึ่งครั้ง"
say "แนะนำปิด battery optimization ให้ Termux ด้วย"
say "ทดสอบตอนนี้ได้ด้วย: bash \"$BOOT_FILE\""
say "ยกเลิกด้วย: bash scripts/install-boot.sh --remove"
