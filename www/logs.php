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

$lines = log_tail($currentLog['file']);
$fileExists = is_file($currentLog['file']);
$fileSize = $fileExists ? (int) filesize($currentLog['file']) : 0;
$fileMtime = $fileExists ? (int) filemtime($currentLog['file']) : 0;

page_start('บันทึก', 'logs');
?>
<div class="page-head">
    <div>
        <p class="eyebrow">LOGS</p>
        <h1>บันทึกของเซิร์ฟเวอร์</h1>
        <p class="sub">ดู 200 บรรทัดล่าสุดของแต่ละไฟล์ได้จากมือถือ ไม่ต้องเข้า Termux</p>
    </div>
    <a class="btn ghost" href="logs.php?f=<?= h($current) ?>">↻ รีเฟรช</a>
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
        <span class="tag">
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
        <pre class="bootlog log-view"><?= h(implode("\n", $lines)) ?></pre>
        <p class="hint small">
            ไฟล์: <code><?= h($currentLog['file']) ?></code>
            <?php if ($fileMtime > 0): ?>
                · แก้ล่าสุด <?= h(date('d/m/Y H:i:s', $fileMtime)) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>
<?php
page_end();
