#!/usr/bin/env bash
# ตรวจหา API key / secret ที่หลุดเข้าไฟล์ในโปรเจกต์ ก่อน push ขึ้น GitHub
#
# ใช้งาน:
#   bash scripts/check-secrets.sh            ตรวจไฟล์ทั้งหมด (ตาม git ถ้ามี)
#   bash scripts/check-secrets.sh --staged   ตรวจเฉพาะไฟล์ที่ stage ไว้
#   bash scripts/check-secrets.sh --install  ติดตั้งเป็น git hook (pre-push)
#
# คืนค่า: 0 = ไม่พบ, 1 = พบ (พร้อมรายชื่อไฟล์:บรรทัด — ไม่พิมพ์ค่าลับออกมา)
set -uo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

MODE="${1:-scan}"

if [ "$MODE" = "--install" ]; then
    if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        printf 'ติดตั้งไม่ได้: โฟลเดอร์นี้ยังไม่เป็น git repository\n' >&2
        printf 'รัน `git init` ก่อน แล้วลองใหม่\n' >&2
        exit 1
    fi

    HOOK_PATH="$(git rev-parse --git-path hooks)/pre-push"
    mkdir -p "$(dirname "$HOOK_PATH")"

    cat >"$HOOK_PATH" <<'HOOK'
#!/usr/bin/env bash
# สร้างโดย scripts/check-secrets.sh — บล็อก push ถ้าพบ key/secret
root="$(git rev-parse --show-toplevel)" || exit 0
exec bash "$root/scripts/check-secrets.sh"
HOOK

    chmod +x "$HOOK_PATH"
    printf 'ติดตั้ง hook แล้ว: %s\n' "$HOOK_PATH"
    printf 'ทดสอบด้วย: bash scripts/check-secrets.sh\n'
    exit 0
fi

# ---------- เลือกไฟล์ที่จะตรวจ ----------
FILES=()
IS_GIT=0

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    IS_GIT=1
    if [ "$MODE" = "--staged" ]; then
        while IFS= read -r file; do
            [ -n "$file" ] && FILES+=("$file")
        done < <(git diff --cached --name-only --diff-filter=ACM 2>/dev/null)
    else
        while IFS= read -r file; do
            [ -n "$file" ] && FILES+=("$file")
        done < <(git ls-files 2>/dev/null)
    fi
fi

if [ "${#FILES[@]}" -eq 0 ]; then
    # ไม่ได้อยู่ใน git repo (หรือยังไม่มีไฟล์ tracked) → สแกนโฟลเดอร์ แต่ข้ามไฟล์ที่ถูก ignore แบบระบุชื่อตรง ๆ
    while IFS= read -r file; do
        rel="${file#./}"
        case "$rel" in
            .git/*|.tools/*|.freebuff/*|node_modules/*|www/files/*) continue ;;
            *.png|*.jpg|*.jpeg|*.gif|*.webp|*.ico|*.zip|*.apk|*.so|*.wasm) continue ;;
        esac
        if [ -f .gitignore ] && grep -Fxq "$rel" .gitignore 2>/dev/null; then
            continue
        fi
        FILES+=("$rel")
    done < <(find . -type f -not -path './.git/*' -not -path './.tools/*' -not -path './.freebuff/*' 2>/dev/null)
fi

if [ "${#FILES[@]}" -eq 0 ]; then
    printf 'ไม่มีไฟล์ให้ตรวจ\n'
    exit 0
fi

# ---------- กฎที่ใช้ตรวจ ----------
RULES=(
    'private-key|-----BEGIN [A-Z ]*PRIVATE KEY-----'
    'aws-access-key|AKIA[0-9A-Z]{16}'
    'github-token|gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{22,}'
    'slack-token|xox[baprs]-[A-Za-z0-9-]{10,}'
    'stripe-live-key|sk_live_[A-Za-z0-9]{20,}'
    'google-api-key|AIza[0-9A-Za-z_-]{35}'
    'assigned-secret|(api[_-]?(key|secret)|secret[_-]?key|access[_-]?token|password)[[:space:]]*[:=][[:space:]]*["'"'"']?[A-Za-z0-9_./+-]{20,}'
    'long-hex-64|[0-9a-fA-F]{64}'
)

findings=0
declare -A reported=()

printf 'ตรวจ %d ไฟล์...\n' "${#FILES[@]}"

for entry in "${RULES[@]}"; do
    name="${entry%%|*}"
    pattern="${entry#*|}"

    for file in "${FILES[@]}"; do
        [ -f "$file" ] || continue

        while IFS=: read -r line_no _; do
            [ -n "${line_no:-}" ] || continue
            key="$file:$line_no"
            [ -n "${reported[$key]:-}" ] && continue
            reported[$key]=1
            findings=$((findings + 1))
            printf '  พบ [%s] ที่ %s:%s\n' "$name" "$file" "$line_no"
        done < <(grep -nE --binary-files=without-match -e "$pattern" "$file" 2>/dev/null | cut -d: -f1,2)
    done
done

printf '\n'

if [ "$findings" -gt 0 ]; then
    printf 'พบ %d จุดที่อาจเป็นความลับ — อย่า push ก่อนแก้\n' "$findings" >&2
    printf 'ย้ายค่าไปไว้ในไฟล์ที่ถูก ignore (เช่น nh.config.php หรือ env) แล้วลบออกจากโค้ด\n' >&2
    exit 1
fi

printf 'ไม่พบ key/secret ต้องสงสัย\n'
if [ "$IS_GIT" = "1" ]; then
    printf 'ติดตั้งเป็น hook กันอัตโนมัติ: bash scripts/check-secrets.sh --install\n'
fi
exit 0
