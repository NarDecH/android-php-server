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
$csrf = csrf_token();

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

    redirect_to('logs.php?f=' . $current . ($lineLimit === 200 ? '' : '&lines=' . $lineLimit));
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
        <p class="sub">ดู <?= h((string) $lineLimit) ?> บรรทัดล่าสุดของแต่ละไฟล์ได้จากมือถือ ไม่ต้องเข้า Termux</p>
    </div>
    <div class="head-actions">
        <?php if ($fileExists && $lines): ?>
            <button class="btn ghost log-live" type="button" id="log-live-toggle" aria-pressed="false"
                    data-endpoint="logs.php?f=<?= h($current) ?>&amp;lines=<?= h((string) $lineLimit) ?>&amp;ajax=1" data-interval="5000">
                <span class="dot-live" aria-hidden="true"></span>
                <span id="log-live-label">ติดตามอัตโนมัติ</span>
            </button>
        <?php endif; ?>
        <?php if ($fileExists): ?>
            <a class="btn ghost" href="logs.php?f=<?= h($current) ?>&amp;download=1">↓ ดาวน์โหลด</a>
        <?php endif; ?>
        <a class="btn ghost" href="logs.php?f=<?= h($current) ?>&amp;lines=<?= h((string) $lineLimit) ?>">↻ รีเฟรช</a>
        <?php if ($fileExists && $lines): ?>
            <form class="inline-form" method="post"
                  action="logs.php?f=<?= h($current) ?>&amp;lines=<?= h((string) $lineLimit) ?>"
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
            <strong>ไฟล์ว่าง</strong>
            <p>ยังไม่มีข้อความถูกบันทึก</p>
        </div>
    <?php else: ?>
        <div class="log-tools">
            <input class="log-filter" id="log-filter" type="search" autocomplete="off"
                   placeholder="กรองบรรทัด… เช่น error, 404, GET" aria-label="กรองบรรทัดใน log">
            <span class="hint small" id="log-filter-count"></span>
            <div class="log-lines" role="group" aria-label="จำนวนบรรทัดที่แสดง">
                <?php foreach ($lineOptions as $option): ?>
                    <a class="chip<?= $option === $lineLimit ? ' active' : '' ?>"
                       href="logs.php?f=<?= h($current) ?>&amp;lines=<?= h((string) $option) ?>"><?= h((string) $option) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <pre class="bootlog log-view" id="log-view" tabindex="0"><?= h(implode("\n", $lines)) ?></pre>
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
