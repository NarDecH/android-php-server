#!/usr/bin/env bash
set -euo pipefail

# ติดตั้ง PHP Server on Android แบบคำสั่งเดียว (สำหรับ Termux จาก F-Droid)
#
# ใช้:
#   curl -fsSL https://raw.githubusercontent.com/NarDecH/android-php-server/main/scripts/bootstrap.sh | bash
#
# กำหนดโฟลเดอร์ติดตั้งเองได้ด้วย PHP_SERVER_DIR (ค่าเริ่มต้น: ~/android-php-server)

REPO_URL="https://github.com/NarDecH/android-php-server.git"
PROJECT_DIR="${PHP_SERVER_DIR:-$HOME/android-php-server}"
DPKG_FORCE_OPTS=(--force-confdef --force-confold)
APT_FORCE_OPTS=(-o "Dpkg::Options::=--force-confdef" -o "Dpkg::Options::=--force-confold")

say() { printf '%s\n' "$*"; }
err() { printf 'ผิดพลาด: %s\n' "$*" >&2; }

if ! command -v pkg >/dev/null 2>&1 || [ -z "${PREFIX:-}" ]; then
    err "สคริปต์นี้ออกแบบมาสำหรับ Termux — ติดตั้ง Termux จาก F-Droid ก่อนแล้วรันใหม่"
    exit 1
fi

say "=== PHP Server on Android — ติดตั้งแบบคำสั่งเดียว ==="
say ""

say "[1/3] ตรวจสอบ git"
if ! command -v git >/dev/null 2>&1; then
    say "      ยังไม่มี git — กำลังติดตั้ง"
    apt update || {
        err "apt update ไม่สำเร็จ — ตรวจสอบอินเทอร์เน็ตแล้วรันสคริปต์นี้อีกครั้ง"
        exit 1
    }
    DEBIAN_FRONTEND=noninteractive dpkg --configure -a "${DPKG_FORCE_OPTS[@]}" || true
    DEBIAN_FRONTEND=noninteractive apt "${APT_FORCE_OPTS[@]}" install -y git || {
        err "ติดตั้ง git ไม่สำเร็จ — ลองรัน 'apt update' แล้วรันสคริปต์นี้อีกครั้ง"
        exit 1
    }
fi
say "      ใช้ $(git --version)"

say "[2/3] เตรียมโค้ดโปรเจกต์ที่ $PROJECT_DIR"
if [ -d "$PROJECT_DIR/.git" ]; then
    if git -C "$PROJECT_DIR" pull --ff-only >/dev/null 2>&1; then
        say "      อัปเดตเป็นโค้ดล่าสุดแล้ว"
    else
        say "      อัปเดตอัตโนมัติไม่ได้ (อาจมีไฟล์ที่แก้ในเครื่อง) — ใช้โค้ดที่มีอยู่ต่อ"
    fi
elif [ -e "$PROJECT_DIR" ]; then
    err "$PROJECT_DIR มีอยู่แล้วและไม่ใช่ git repository"
    err "ย้าย/ลบโฟลเดอร์นั้น หรือกำหนดตำแหน่งใหม่ เช่น PHP_SERVER_DIR=~/php-server"
    exit 1
else
    git clone --depth 1 "$REPO_URL" "$PROJECT_DIR"
fi

say "[3/3] ติดตั้งและตั้งค่า (setup.sh)"
bash "$PROJECT_DIR/scripts/setup.sh"

say ""
say "เสร็จแล้ว! ขั้นตอนถัดไป (รันใน Termux):"
say "  cd $PROJECT_DIR"
say "  bash scripts/server.sh start     # เริ่มเซิร์ฟเวอร์"
say "  bash scripts/install-boot.sh     # เปิดอัตโนมัติเมื่อบูต (ต้องมี Termux:Boot)"
say "  termux-setup-storage             # ถ้าต้องการนำเข้าไฟล์จาก Downloads"
