#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
STATE_DIR="${PHP_SERVER_STATE_DIR:-$HOME/.php-server}"
CONFIG_FILE="$STATE_DIR/config.env"
TARGET_ROOT="$ROOT_DIR/www/files"

FORCE=0
TO=""
SOURCE=""
LIST_ONLY=0
PORT="8080"

if [ -f "$CONFIG_FILE" ]; then
    # shellcheck disable=SC1090
    set -a
    . "$CONFIG_FILE"
    set +a
fi
PORT="${PORT:-8080}"

say() { printf '%s\n' "$*"; }
err() { printf 'ผิดพลาด: %s\n' "$*" >&2; }

usage() {
    cat <<'EOF'
ใช้งาน: bash scripts/import.sh [ต้นทาง] [ตัวเลือก]

ถ้าไม่ระบุต้นทาง จะเปิดเมนูเลือกไฟล์จาก ~/storage/downloads

ตัวอย่าง:
  bash scripts/import.sh
  bash scripts/import.sh ~/storage/downloads/mysite.zip
  bash scripts/import.sh ~/storage/downloads/index.php
  bash scripts/import.sh ~/storage/downloads/mysite --to demo
  bash scripts/import.sh ~/storage/downloads/mysite.zip --force
  bash scripts/import.sh --list

ตัวเลือก:
  --to <ชื่อโฟลเดอร์>  เก็บใน www/files/<ชื่อโฟลเดอร์>/
  --force             เขียนทับ/รวมไฟล์ที่มีอยู่
  --list              แสดงรายการไฟล์ใน www/files
  -h, --help          แสดงข้อความนี้

ไฟล์และโฟลเดอร์ทั้งหมดจะถูกเก็บใต้ www/files/
EOF
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --force)
            FORCE=1
            shift
            ;;
        --to)
            if [ "$#" -lt 2 ]; then
                err "--to ต้องตามด้วยชื่อโฟลเดอร์"
                exit 1
            fi
            TO="$2"
            shift 2
            ;;
        --list)
            LIST_ONLY=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        -*)
            err "ไม่รู้จักตัวเลือก: $1"
            usage
            exit 1
            ;;
        *)
            if [ -n "$SOURCE" ]; then
                err "ระบุต้นทางได้เพียงรายการเดียว"
                exit 1
            fi
            SOURCE="$1"
            shift
            ;;
    esac
done

if [ -n "$TO" ]; then
    TO="${TO#./}"
    TO="${TO%/}"

    case "$TO" in
        ""|/*)
            err "--to ต้องเป็นชื่อโฟลเดอร์ย่อย ไม่ใช่ path แบบ absolute"
            exit 1
            ;;
        *\\*)
            err "--to ห้ามมีเครื่องหมาย \\"
            exit 1
            ;;
    esac

    IFS='/' read -r -a to_parts <<< "$TO"
    for part in "${to_parts[@]}"; do
        if [ -z "$part" ] || [ "$part" = "." ] || [ "$part" = ".." ]; then
            err "--to มี path ไม่ถูกต้อง"
            exit 1
        fi
    done
fi

mkdir -p "$TARGET_ROOT"

encode_path() {
    if command -v php >/dev/null 2>&1; then
        php -r 'echo implode("/", array_map("rawurlencode", explode("/", $argv[1])));' "$1"
    else
        printf '%s' "$1"
    fi
}

server_is_running() {
    local pid_file="$STATE_DIR/server.pid"
    [ -f "$pid_file" ] || return 1

    local pid
    pid="$(cat "$pid_file" 2>/dev/null || true)"
    [[ "$pid" =~ ^[0-9]+$ ]] || return 1
    kill -0 "$pid" 2>/dev/null
}

list_files() {
    say "ไฟล์ใน www/files:"

    local found=0
    while IFS= read -r -d '' path; do
        found=1
        local rel="${path#"$TARGET_ROOT"/}"
        if [ -d "$path" ]; then
            printf '  [โฟลเดอร์] %s\n' "$rel"
        else
            printf '  %8s  %s\n' "$(du -h "$path" 2>/dev/null | cut -f1)" "$rel"
        fi
    done < <(find "$TARGET_ROOT" -mindepth 1 -maxdepth 4 -not -name '.gitkeep' -print0 2>/dev/null)

    if [ "$found" -eq 0 ]; then
        say "  (ว่าง)"
    fi
}

print_result() {
    local target="$1"
    local is_dir_target="$2"
    local rel="${target#"$ROOT_DIR/www/"}"
    local url="http://127.0.0.1:${PORT}/$(encode_path "$rel")"

    if [ "$is_dir_target" -eq 1 ]; then
        url="${url}/"
    fi

    say ""
    say "✓ นำเข้าเรียบร้อย"
    say "  ที่เก็บ: $target"
    say "  เปิด:   $url"

    if [ "$is_dir_target" -eq 1 ] && [ -f "$target/index.php" ]; then
        say "  พบ index.php — เปิด URL ด้านบนได้เลย"
    fi

    if ! server_is_running; then
        say "  หมายเหตุ: เซิร์ฟเวอร์ยังไม่ทำงาน — เริ่มด้วย bash scripts/server.sh start"
    fi
}

import_source() {
    local source="$1"

    case "$source" in
        "~/"*) source="$HOME/${source#\~/}" ;;
    esac

    if [ ! -e "$source" ]; then
        err "ไม่พบต้นทาง: $source"
        return 1
    fi

    local base
    base="$(basename "$source")"
    local is_zip=0
    case "${source,,}" in
        *.zip) is_zip=1 ;;
    esac

    local dest
    if [ -n "$TO" ]; then
        dest="$TARGET_ROOT/$TO"
    elif [ -d "$source" ]; then
        dest="$TARGET_ROOT/$base"
    elif [ "$is_zip" -eq 1 ]; then
        dest="$TARGET_ROOT/${base%.*}"
        [ -n "${base%.*}" ] || dest="$TARGET_ROOT/imported"
    else
        dest="$TARGET_ROOT"
    fi

    if [ -d "$source" ] || [ "$is_zip" -eq 1 ]; then
        if [ -d "$dest" ] && [ -n "$(ls -A "$dest" 2>/dev/null || true)" ] && [ "$FORCE" -ne 1 ]; then
            err "ปลายทางมีไฟล์อยู่แล้ว: $dest (ใช้ --force เพื่อเขียนทับ/รวม)"
            return 1
        fi
    fi

    if ! mkdir -p "$dest"; then
        err "สร้างโฟลเดอร์ปลายทางไม่ได้: $dest"
        return 1
    fi

    if [ -d "$source" ]; then
        if ! cp -R "$source"/. "$dest"/; then
            err "คัดลอกโฟลเดอร์ไม่สำเร็จ"
            return 1
        fi
        print_result "$dest" 1
        return 0
    fi

    if [ "$is_zip" -eq 1 ]; then
        if ! command -v unzip >/dev/null 2>&1; then
            err "ไม่พบคำสั่ง unzip — รัน: pkg install unzip"
            return 1
        fi

        local unzip_flags="-n"
        [ "$FORCE" -eq 1 ] && unzip_flags="-o"

        if ! unzip "$unzip_flags" -q "$source" -d "$dest"; then
            err "แตกไฟล์ ZIP ไม่สำเร็จ"
            return 1
        fi
        print_result "$dest" 1
        return 0
    fi

    local file_target="$dest/$base"
    if [ -e "$file_target" ] && [ "$FORCE" -ne 1 ]; then
        err "ไฟล์ปลายทางมีอยู่แล้ว: $file_target (ใช้ --force เพื่อเขียนทับ)"
        return 1
    fi

    if ! cp -f "$source" "$file_target"; then
        err "คัดลอกไฟล์ไม่สำเร็จ"
        return 1
    fi

    print_result "$file_target" 0
}

interactive_select() {
    local downloads="$HOME/storage/downloads"

    if [ ! -d "$downloads" ]; then
        err "ไม่พบโฟลเดอร์ Downloads"
        say "รัน 'termux-setup-storage' แล้วอนุญาตการเข้าถึงไฟล์ก่อน"
        exit 1
    fi

    if [ ! -t 0 ]; then
        err "โหมดเลือกไฟล์ต้องรันใน terminal แบบโต้ตอบ"
        usage
        exit 1
    fi

    shopt -s nullglob
    local entries=("$downloads"/*)
    shopt -u nullglob

    if [ "${#entries[@]}" -eq 0 ]; then
        say "ไม่พบไฟล์ใน $downloads"
        return 0
    fi

    if [ "${#entries[@]}" -gt 50 ]; then
        entries=("${entries[@]:0:50}")
        say "(แสดง 50 รายการแรก — ถ้าไม่พบไฟล์ที่ต้องการ ให้ระบุ path โดยตรง)"
    fi

    say "ไฟล์ใน Downloads:"
    local i entry
    for i in "${!entries[@]}"; do
        entry="${entries[$i]}"
        if [ -d "$entry" ]; then
            printf '  %2d) %s  [โฟลเดอร์]\n' "$((i + 1))" "$(basename "$entry")"
        else
            printf '  %2d) %s  (%s)\n' "$((i + 1))" "$(basename "$entry")" "$(du -h "$entry" 2>/dev/null | cut -f1)"
        fi
    done

    say ""
    local choice
    read -r -p "เลือกหมายเลข (q = ยกเลิก): " choice || true

    case "$choice" in
        q|Q|"")
            say "ยกเลิก"
            return 0
            ;;
    esac

    if ! [[ "$choice" =~ ^[0-9]+$ ]] || [ "$choice" -lt 1 ] || [ "$choice" -gt "${#entries[@]}" ]; then
        err "หมายเลขไม่ถูกต้อง"
        exit 1
    fi

    import_source "${entries[$((choice - 1))]}"
}

if [ "$LIST_ONLY" -eq 1 ]; then
    if [ -n "$SOURCE" ]; then
        err "--list ใช้ร่วมกับต้นทางไม่ได้"
        exit 1
    fi
    list_files
    exit 0
fi

if [ -n "$SOURCE" ]; then
    import_source "$SOURCE"
else
    if [ -n "$TO" ]; then
        err "--to ต้องใช้คู่กับต้นทาง"
        exit 1
    fi
    interactive_select
fi
