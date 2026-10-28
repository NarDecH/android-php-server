<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';
start_session();

$stateDir = rtrim((string) (getenv('PHP_SERVER_STATE_DIR') ?: ((getenv('HOME') ?: '') . '/.php-server')), '/');

$logs = [
    'server' => ['label' => 'เซิร์ฟเวอร์ (server.log)', 'file' => $stateDir . '/server.log', 'hint' => 'คำขอที่ PHP built-in server รับเข้ามา'],
    'php' => ['label' => 'PHP errors (php-error.log)', 'file' => $stateDir . '/php-error.log', 'hint' => 'warning / deprecated / fatal error ของ PHP'],
    'boot' => ['label' => 'Autostart (boot.log)', 'file' => $stateDir . '/boot.log', 'hint' => 'ผลการทำงานของสคริปต์ Termux:Boot หลังรีบูต'],
    'settings' => ['label' => 'การตั้งค่า (settings.log)', 'file' => $stateDir . '/settings.log', 'hint' => 'ผลการรีสตาร์ตหลังแก้ค่าจากหน้า settings'],
];

$requested = (string) ($_GET['f'] ?? 'server');
$current = array_key_exists($requested, $logs) ? $requested : 'server';
$currentLog = $logs[$current];

$lineOptions = [200, 500, 1000];
$requestedLines = (int) ($_GET['lines'] ?? 200);
$lineLimit = in_array($requestedLines, $lineOptions, true) ? $requestedLines : 200;
$sinceOptions = [15 => '15 นาที', 60 => '1 ชม.', 1440 => '24 ชม.'];
$requestedSince = (int) ($_GET['since'] ?? 0);
$sinceMinutes = array_key_exists($requestedSince, $sinceOptions) ? $requestedSince : 0;
$csrf = csrf_token();

/**
 * สร้าง URL ของหน้านี้โดยคงค่า f/lines/since
 *
 * @param array<string, string> $extra
 */
function logs_url(string $file, int $lines, int $since, array $extra = []): string
{
    $params = ['f' => $file];

    if ($lines !== 200) {
        $params['lines'] = (string) $lines;
    }
    if ($since > 0) {
        $params['since'] = (string) $since;
    }

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    return 'logs.php?' . http_build_query($params);
}

/**
 * อ่านบรรทัดท้าย ๆ ของไฟล์ โดยไม่โหลดทั้งไฟล์ถ้าใหญ่เกิน 512 KB
 *
 * @return array<int, string>
 */
function log_tail(string $file, int $lines = 200): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    $size = (int) filesize($file);
    $maxBytes = 512 * 1024;
    $handle = @fopen($file, 'rb');

    if ($handle === false) {
        return [];
    }

    if ($size > $maxBytes) {
        fseek($handle, $size - $maxBytes);
    }

    $data = (string) stream_get_contents($handle);
    fclose($handle);

    $rows = preg_split('/\r\n|\r|\n/', $data) ?: [];
    $rows = array_values(array_filter($rows, static fn (string $row): bool => trim($row) !== ''));

    return array_slice($rows, -$lines);
}

/**
 * อ่านเวลา (epoch) จากบรรทัด log รูปแบบที่โปรเจกต์ใช้
 */
function log_line_timestamp(string $line): ?int
{
    $months = [
        'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
        'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
    ];

    // [Wed Oct 28 14:01:16 2026] — server.log มี [pid] นำหน้า
    if (preg_match('/\[[A-Z][a-z]{2} ([A-Z][a-z]{2}) (\d{1,2}) (\d{2}):(\d{2}):(\d{2}) (\d{4})\]/', $line, $m) === 1
        && isset($months[$m[1]])) {
        return mktime((int) $m[3], (int) $m[4], (int) $m[5], $months[$m[1]], (int) $m[2], (int) $m[6]) ?: null;
    }

    // [28-Oct-2026 10:23:52 Asia/Bangkok]
    if (preg_match('/\[(\d{1,2})-([A-Z][a-z]{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})/', $line, $m) === 1
        && isset($months[$m[2]])) {
        return mktime((int) $m[4], (int) $m[5], (int) $m[6], $months[$m[2]], (int) $m[1], (int) $m[3]) ?: null;
    }

    // [2026-10-28 14:00:00]
    if (preg_match('/\[(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\]/', $line, $m) === 1) {
        return mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]) ?: null;
    }

    return null;
}

/**
 * เก็บเฉพาะบรรทัดในช่วงเวลาที่กำหนด (บรรทัดต่อเนื่องที่ไม่มีเวลาใช้ค่าของบรรทัดก่อน)
 *
 * @param array<int, string> $lines
 * @return array<int, string>
 */
function log_lines_since(array $lines, int $seconds): array
{
    $cutoff = time() - $seconds;
    $kept = [];
    $inRange = false;

    foreach ($lines as $line) {
        $timestamp = log_line_timestamp($line);

        if ($timestamp !== null) {
            $inRange = $timestamp >= $cutoff;
        }

        if ($inRange) {
            $kept[] = $line;
        }
    }

    return $kept;
}

/**
 * อ่านเนื้อหาไฟล์ท้าย ๆ ไม่เกิน maxBytes (กันไฟล์ใหญ่เกินหน่วยความจำ)
 */
function log_tail_bytes(string $file, int $maxBytes = 4 * 1024 * 1024): string
{
    $size = (int) @filesize($file);
    $handle = @fopen($file, 'rb');

    if ($handle === false) {
        return '';
    }

    if ($size > $maxBytes) {
        fseek($handle, $size - $maxBytes);
    }

    $data = (string) stream_get_contents($handle);
    fclose($handle);

    return $data;
}

/**
 * สร้าง ZIP แบบไม่บีบอัดในหน่วยความจำ (ไม่ต้องใช้ไฟล์ชั่วคราวหรือส่วนขยาย zip)
 *
 * @param array<string, string> $files
 */
function zip_store(array $files): string
{
    $data = '';
    $central = '';
    $count = 0;
    $offset = 0;

    foreach ($files as $name => $content) {
        $name = (string) $name;
        $content = (string) $content;
        $crc = crc32($content);
        $size = strlen($content);
        $nameLength = strlen($name);

        // บีบอัดแบบ deflate ถ้าช่วยลดขนาดได้จริง
        $deflated = function_exists('gzdeflate') ? @gzdeflate($content, 6) : false;
        $method = $deflated !== false && strlen($deflated) < $size ? 8 : 0;
        $payload = $method === 8 ? $deflated : $content;
        $packedSize = strlen($payload);

        $local = "PK\x03\x04"
            . pack('v', 20)
            . pack('v', 0x0800)
            . pack('v', $method)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', $crc)
            . pack('V', $packedSize)
            . pack('V', $size)
            . pack('v', $nameLength)
            . pack('v', 0)
            . $name;

        $data .= $local . $payload;

        $central .= "PK\x01\x02"
            . pack('v', 20)
            . pack('v', 20)
            . pack('v', 0x0800)
            . pack('v', $method)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', $crc)
            . pack('V', $packedSize)
            . pack('V', $size)
            . pack('v', $nameLength)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', 0)
            . pack('V', $offset)
            . $name;

        $offset += strlen($local) + $packedSize;
        $count++;
    }

    return $data . $central
        . "PK\x05\x06"
        . pack('v', 0)
        . pack('v', 0)
        . pack('v', $count)
        . pack('v', $count)
        . pack('V', strlen($central))
        . pack('V', $offset)
        . pack('v', 0);
}

/**
 * รวม log ที่มีอยู่เป็น ZIP ให้ดาวน์โหลดครั้งเดียว
 */
function logs_zip_payload(array $logs): ?string
{
    $entries = [];

    foreach ($logs as $log) {
        if (!is_file($log['file']) || !is_readable($log['file'])) {
            continue;
        }

        $entries[basename($log['file'])] = log_tail_bytes($log['file']);
    }

    if (!$entries) {
        return null;
    }

    $info = 'PHP Server on Android — log export' . "\n"
        . 'created: ' . date('d/m/Y H:i:s') . "\n"
        . 'host: ' . (string) ($_SERVER['HTTP_HOST'] ?? '-') . "\n"
        . 'php: ' . PHP_VERSION . "\n"
        . 'note: each file contains at most the last 4 MB' . "\n";

    return zip_store(['info.txt' => $info] + $entries);
}

$fileExists = is_file($currentLog['file']);
$fileSize = $fileExists ? (int) filesize($currentLog['file']) : 0;
$fileMtime = $fileExists ? (int) filemtime($currentLog['file']) : 0;

// ล้างไฟล์ (เฉพาะชื่อไฟล์ใน whitelist) — ต้องมี CSRF และเซิร์ฟเวอร์เปิด log แบบ append
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'CSRF token ไม่ถูกต้อง — รีเฟรชหน้าแล้วลองใหม่');
    } elseif (!$fileExists) {
        flash_set('warning', 'ยังไม่มีไฟล์นี้ให้ล้าง');
    } elseif (!is_writable($currentLog['file'])) {
        flash_set('error', 'ไม่มีสิทธิ์เขียนไฟล์นี้');
    } else {
        $handle = @fopen($currentLog['file'], 'wb');

        if ($handle === false) {
            flash_set('error', 'ล้างไฟล์ไม่สำเร็จ');
        } else {
            fclose($handle);
            flash_set('success', 'ล้าง ' . basename($currentLog['file']) . ' แล้ว');
        }
    }

    redirect_to(logs_url($current, $lineLimit, $sinceMinutes));
}

// ดาวน์โหลด log ทั้ง 4 ไฟล์เป็น ZIP เดียว
if (($_GET['download'] ?? '') === 'all') {
    $zip = logs_zip_payload($logs);

    if ($zip === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'ยังไม่มีไฟล์ log ให้ดาวน์โหลด';
        exit;
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="php-server-logs-' . date('Ymd-Hi') . '.zip"');
    header('Content-Length: ' . strlen($zip));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $zip;
    exit;
}

// ดาวน์โหลดไฟล์เต็ม — ชื่อไฟล์มาจาก whitelist ด้านบน ไม่รับ path จากผู้ใช้
if (isset($_GET['download'])) {
    if (!$fileExists) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'ยังไม่มีไฟล์นี้';
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($currentLog['file']) . '"');
    header('Content-Length: ' . (string) $fileSize);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($currentLog['file']);
    exit;
}

$lines = log_tail($currentLog['file'], $lineLimit);

if ($sinceMinutes > 0) {
    $lines = log_lines_since($lines, $sinceMinutes * 60);
}

// โหมดติดตามอัตโนมัติ: คืนเฉพาะข้อมูลที่ต้องอัปเดตเป็น JSON
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => true,
        'file' => $current,
        'exists' => $fileExists,
        'text' => implode("\n", $lines),
        'count' => count($lines),
        'size' => format_bytes($fileSize),
        'mtime' => $fileMtime > 0 ? date('d/m/Y H:i:s', $fileMtime) : '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

page_start('บันทึก', 'logs');
?>
<?php render_flashes(); ?>
<div class="page-head">
    <div>
        <p class="eyebrow">LOGS</p>
        <h1>บันทึกของเซิร์ฟเวอร์</h1>
        <p class="sub">
            ดู <?= h((string) $lineLimit) ?> บรรทัดล่าสุดของแต่ละไฟล์ได้จากมือถือ ไม่ต้องเข้า Termux
            <?php if ($sinceMinutes > 0): ?>
                · เฉพาะ <?= h($sinceOptions[$sinceMinutes]) ?>ล่าสุด
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if ($fileExists && $lines): ?>
            <button class="btn ghost log-live" type="button" id="log-live-toggle" aria-pressed="false"
                    data-endpoint="<?= h(logs_url($current, $lineLimit, $sinceMinutes, ['ajax' => '1'])) ?>" data-interval="5000">
                <span class="dot-live" aria-hidden="true"></span>
                <span id="log-live-label">ติดตามอัตโนมัติ</span>
            </button>
        <?php endif; ?>
        <?php if ($fileExists): ?>
            <a class="btn ghost" href="<?= h(logs_url($current, 200, 0, ['download' => '1'])) ?>">↓ ดาวน์โหลด</a>
        <?php endif; ?>
        <?php
        $anyLogFile = false;
        foreach ($logs as $log) {
            if (is_file($log['file'])) {
                $anyLogFile = true;
                break;
            }
        }
        ?>
        <?php if ($anyLogFile): ?>
            <a class="btn ghost" href="<?= h(logs_url($current, 200, 0, ['download' => 'all'])) ?>">↓ ทั้งหมด (.zip)</a>
        <?php endif; ?>
        <a class="btn ghost" href="<?= h(logs_url($current, $lineLimit, $sinceMinutes)) ?>">↻ รีเฟรช</a>
        <?php if ($fileExists && $lines): ?>
            <form class="inline-form" method="post"
                  action="<?= h(logs_url($current, $lineLimit, $sinceMinutes)) ?>"
                  data-confirm="ล้างไฟล์ <?= h(basename($currentLog['file'])) ?> ทั้งหมด? การกระทำนี้ย้อนกลับไม่ได้">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="clear">
                <button class="btn danger" type="submit">ล้างไฟล์</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="tabs">
    <?php foreach ($logs as $key => $log): ?>
        <a class="tab<?= $key === $current ? ' active' : '' ?>" href="logs.php?f=<?= h($key) ?>">
            <?= h(explode(' ', $log['label'])[0]) ?>
        </a>
    <?php endforeach; ?>
</div>

<section class="card">
    <div class="card-head">
        <h2><?= h($currentLog['label']) ?></h2>
        <span class="tag" id="log-meta">
            <?php if ($fileExists): ?>
                <?= h((string) count($lines)) ?> บรรทัด · <?= h(format_bytes($fileSize)) ?>
            <?php else: ?>
                ยังไม่มีไฟล์
            <?php endif; ?>
        </span>
    </div>

    <p class="hint small"><?= h($currentLog['hint']) ?></p>

    <?php if (!$fileExists): ?>
        <div class="empty">
            <div class="empty-icon" aria-hidden="true">🗒</div>
            <strong>ยังไม่มีไฟล์นี้</strong>
            <p>ไฟล์จะถูกสร้างเมื่อมีเหตุการณ์ที่เกี่ยวข้อง</p>
        </div>
    <?php elseif (!$lines): ?>
        <div class="empty">
            <strong><?= $sinceMinutes > 0 ? 'ไม่พบบรรทัดในช่วงเวลานี้' : 'ไฟล์ว่าง' ?></strong>
            <p>
                <?= $sinceMinutes > 0
                    ? 'ลองเลือกช่วงเวลาที่กว้างขึ้น หรือเพิ่มจำนวนบรรทัด'
                    : 'ยังไม่มีข้อความถูกบันทึก' ?>
            </p>
        </div>
    <?php else: ?>
        <div class="log-tools">
            <input class="log-filter" id="log-filter" type="search" autocomplete="off"
                   placeholder="กรองบรรทัด… เช่น error, 404, GET" aria-label="กรองบรรทัดใน log">
            <button class="btn tiny ghost" type="button" id="log-copy">คัดลอก</button>
            <button class="btn tiny ghost" type="button" id="log-save"
                    title="ดาวน์โหลดเฉพาะบรรทัดที่แสดงอยู่"
                    data-file="<?= h(basename($currentLog['file'])) ?>">↓ .txt</button>
            <span class="hint small" id="log-filter-count"></span>
            <div class="log-range" role="group" aria-label="ช่วงเวลาของ log">
                <a class="chip<?= $sinceMinutes === 0 ? ' active' : '' ?>"
                   href="<?= h(logs_url($current, $lineLimit, 0)) ?>">ทั้งหมด</a>
                <?php foreach ($sinceOptions as $minutes => $label): ?>
                    <a class="chip<?= $minutes === $sinceMinutes ? ' active' : '' ?>"
                       href="<?= h(logs_url($current, $lineLimit, $minutes)) ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="log-lines" role="group" aria-label="จำนวนบรรทัดที่แสดง">
                <?php foreach ($lineOptions as $option): ?>
                    <a class="chip<?= $option === $lineLimit ? ' active' : '' ?>"
                       href="<?= h(logs_url($current, $option, $sinceMinutes)) ?>"><?= h((string) $option) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="log-scroll">
            <pre class="bootlog log-view" id="log-view" tabindex="0"><?= h(implode("\n", $lines)) ?></pre>
            <div class="log-jump">
                <button class="btn tiny ghost" type="button" id="log-top" aria-label="เลื่อนไปบนสุด">↑</button>
                <button class="btn tiny ghost" type="button" id="log-bottom" aria-label="เลื่อนไปท้ายสุด">↓</button>
            </div>
        </div>
        <p class="hint small">
            ไฟล์: <code><?= h($currentLog['file']) ?></code>
            <?php if ($fileMtime > 0): ?>
                · แก้ล่าสุด <span id="log-mtime"><?= h(date('d/m/Y H:i:s', $fileMtime)) ?></span>
            <?php endif; ?>
            <span id="log-live-status"></span>
        </p>
    <?php endif; ?>
</section>
<?php
page_end();
