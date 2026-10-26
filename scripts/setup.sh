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

php_works() {
    php -r 'echo PHP_VERSION;' >/dev/null 2>&1
}

# กัน apt/dpkg ค้างที่คำถาม conffile (เช่น openssl.cnf) ระหว่างซ่อมแพ็กเกจ
DPKG_FORCE_OPTS=(--force-confdef --force-confold)
APT_FORCE_OPTS=(-o "Dpkg::Options::=--force-confdef" -o "Dpkg::Options::=--force-confold")

# Termux บางเครื่องมีแพ็กเกจค้างจากรอบก่อน ทำให้ php/curl link ไม่ได้
# (เช่น CANNOT LINK EXECUTABLE "php": SSL_set_quic_tls_early_data_enabled)
# apt full-upgrade จะซ่อม libcurl/OpenSSL ให้ตรงรุ่นกัน
termux_repair_packages() {
    say "      พบแพ็กเกจ Termux เวอร์ชันไม่ตรงกัน — กำลังซ่อมด้วย apt full-upgrade"
    if ! command -v apt >/dev/null 2>&1; then
        return 1
    fi

    apt update || return 1
    DEBIAN_FRONTEND=noninteractive dpkg --configure -a "${DPKG_FORCE_OPTS[@]}" || true
    DEBIAN_FRONTEND=noninteractive apt "${APT_FORCE_OPTS[@]}" full-upgrade -y || return 1
}

termux_install_packages() {
    if DEBIAN_FRONTEND=noninteractive apt "${APT_FORCE_OPTS[@]}" install -y php curl unzip; then
        return 0
    fi

    say "      ติดตั้งแพ็กเกจไม่สำเร็จ — ลองซ่อมแพ็กเกจก่อนติดตั้งใหม่"
    termux_repair_packages || return 1
    DEBIAN_FRONTEND=noninteractive apt "${APT_FORCE_OPTS[@]}" install -y php curl unzip
}

if command -v pkg >/dev/null 2>&1 && [ -n "${PREFIX:-}" ] && [[ "${PREFIX}" == *"com.termux"* ]]; then
    say "[1/3] ตรวจพบ Termux — กำลังติดตั้ง PHP, curl และ unzip"
    if ! termux_install_packages; then
        fail "ติดตั้งแพ็กเกจไม่สำเร็จ — ลองรัน 'apt update && apt full-upgrade -y' แล้วรัน setup อีกครั้ง"
    fi

    if ! php_works; then
        say "      PHP ที่ติดตั้งยังทำงานไม่ได้ (มักเกิดจาก libcurl/OpenSSL คนละเวอร์ชัน)"
        if ! termux_repair_packages; then
            fail "ซ่อมแพ็กเกจไม่สำเร็จ — รัน 'apt update && apt full-upgrade -y' แล้วรัน setup อีกครั้ง"
        fi
        php_works || fail "ยังเรียก php ไม่ได้หลังซ่อมแพ็กเกจ — ลองปิด/เปิด Termux แล้วรัน setup อีกครั้ง"
    fi
else
    say "[1/3] ไม่ได้รันใน Termux — ข้ามการติดตั้งแพ็กเกจ (ต้องมี php อยู่แล้ว)"
fi

command -v php >/dev/null 2>&1 || fail "ไม่พบคำสั่ง php — ติดตั้งด้วย 'pkg install php' ก่อน"
php_works || fail "php ทำงานไม่ได้ — รัน 'apt update && apt full-upgrade -y' เพื่อซ่อมแพ็กเกจ Termux"
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
downloads="$HOME/storage/downloads"
if [ -d "$downloads" ] && [ -r "$downloads" ] && [ -x "$downloads" ]; then
    say "      พบ ~/storage/downloads และอ่านได้แล้ว"
elif [ -L "$downloads" ]; then
    say "      พบ ~/storage/downloads แต่เข้าถึงเป้าหมายไม่ได้ (สิทธิ์ถูกปฏิเสธ)"
    say "      เปิด Settings → Apps → Termux → Permissions/Special access"
    say "      แล้วอนุญาต Files and media หรือ All files access จากนั้นรัน termux-setup-storage อีกครั้ง"
else
    say "      ยังไม่มี ~/storage/downloads"
    say "      ถ้าต้องการนำเข้าไฟล์จาก Downloads ให้รัน: termux-setup-storage"
    say "      แล้วอนุญาต Files and media (หรือ All files access) ให้ Termux"
fi

say ""
say "พร้อมใช้งาน! ขั้นตอนถัดไป:"
say "  bash scripts/server.sh start"
say "  bash scripts/server.sh open"
say ""
say "หรือเปิดเบราว์เซอร์ที่ http://127.0.0.1:8080"
