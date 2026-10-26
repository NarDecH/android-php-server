<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';

$files = imported_files();
$fileCount = count($files);
$filesTotalSize = array_sum(array_column($files, 'size'));
$diskFree = @disk_free_space(__DIR__);
$diskTotal = @disk_total_space(__DIR__);
$diskFree = $diskFree === false ? 0 : (int) $diskFree;
$diskTotal = $diskTotal === false ? 0 : (int) $diskTotal;
$maxFileUpload = ini_bytes('upload_max_filesize');
$maxPostSize = ini_bytes('post_max_size');
$maxFileUploads = (int) ini_get('max_file_uploads');
$isLocal = is_local_request();
$filesWritable = is_dir(files_path()) && is_writable(files_path());

$checks = [
    [
        'label' => 'PHP เวอร์ชัน 8.1 ขึ้นไป',
        'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'detail' => PHP_VERSION,
    ],
    [
        'label' => 'เปิดใช้งานการอัปโหลดไฟล์',
        'ok' => (bool) ini_get('file_uploads'),
        'detail' => ini_get('file_uploads') ? 'file_uploads = On' : 'file_uploads = Off',
    ],
    [
        'label' => 'โฟลเดอร์ www/files เขียนได้',
        'ok' => $filesWritable,
        'detail' => 'www/files',
    ],
    [
        'label' => 'พื้นที่ว่างมากกว่า 50 MB',
        'ok' => $diskFree > 50 * 1024 * 1024,
        'detail' => format_bytes($diskFree) . ' ว่าง',
    ],
];

page_start('ภาพรวมเซิร์ฟเวอร์', 'home');
?>

<?php if (!$isLocal): ?>
    <div class="alert alert-warning">
        คุณกำลังเปิดหน้านี้จากอุปกรณ์อื่นในเครือข่าย — ตรวจสอบว่าได้ตั้ง <code>UPLOAD_PIN</code>
        และอย่าเปิดพอร์ตนี้สู่อินเทอร์เน็ต
    </div>
<?php endif; ?>

<section class="hero">
    <p class="eyebrow">LOCAL PHP SERVER · READY</p>
    <h1>เซิร์ฟเวอร์ PHP บนมือถือ<br><span class="gradient-text">พร้อมใช้งานแล้ว</span></h1>
    <p class="hero-lead">
        ไฟล์ทั้งหมดในโฟลเดอร์ <code>www/</code> กำลังถูกเสิร์ฟจากเครื่องนี้
        เปิดเบราว์เซอร์ที่ <a href="<?= h(server_base_url()) ?>/"><?= h(server_base_url()) ?></a>
        แล้วเริ่มเขียนหรือนำเข้าเว็บไซต์ได้ทันที
    </p>
    <div class="hero-actions">
        <a class="btn primary" href="upload.php">＋ นำเข้าไฟล์</a>
        <a class="btn" href="#files">ดูไฟล์ที่นำเข้า (<?= h((string) $fileCount) ?>)</a>
        <a class="btn ghost" href="#commands">คำสั่งจัดการเซิร์ฟเวอร์</a>
    </div>
</section>

<div class="grid two">
    <section class="card">
        <div class="card-head">
            <h2>สถานะเซิร์ฟเวอร์</h2>
            <span class="tag ok">ONLINE</span>
        </div>
        <dl class="kv">
            <div>
                <dt>ที่อยู่เว็บ</dt>
                <dd><code><?= h(server_base_url()) ?>/</code></dd>
            </div>
            <div>
                <dt>PHP</dt>
                <dd><?= h(PHP_VERSION) ?> <span class="muted">(<?= h(PHP_SAPI) ?>)</span></dd>
            </div>
            <div>
                <dt>เว็บเซิร์ฟเวอร์</dt>
                <dd><?= h($_SERVER['SERVER_SOFTWARE'] ?? 'PHP built-in server') ?></dd>
            </div>
            <div>
                <dt>ระบบปฏิบัติการ</dt>
                <dd><?= h(php_uname('s') . ' ' . php_uname('r')) ?> <span class="muted">(<?= h(php_uname('m')) ?>)</span></dd>
            </div>
            <div>
                <dt>เวลาเครื่องแม่ข่าย</dt>
                <dd><?= h(date('d/m/Y H:i:s')) ?> <span class="muted">(<?= h(date_default_timezone_get()) ?>)</span></dd>
            </div>
            <div>
                <dt>Document root</dt>
                <dd><code class="break"><?= h(__DIR__) ?></code></dd>
            </div>
            <div>
                <dt>พื้นที่ดิสก์</dt>
                <dd><?= h(format_bytes($diskFree)) ?> ว่าง <span class="muted">จาก <?= h(format_bytes($diskTotal)) ?></span></dd>
            </div>
            <div>
                <dt>อัปโหลดสูงสุด</dt>
                <dd>
                    <?= h(format_bytes($maxFileUpload)) ?> / ไฟล์
                    <span class="muted">· <?= h(format_bytes($maxPostSize)) ?> / คำขอ · <?= h((string) $maxFileUploads) ?> ไฟล์</span>
                </dd>
            </div>
        </dl>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>ความพร้อมของระบบ</h2>
            <span class="tag <?= $filesWritable ? 'ok' : 'bad' ?>">
                <?= $filesWritable ? 'พร้อมใช้' : 'ต้องแก้ไข' ?>
            </span>
        </div>
        <ul class="checks">
            <?php foreach ($checks as $check): ?>
                <li class="<?= $check['ok'] ? 'pass' : 'fail' ?>">
                    <span class="check-icon" aria-hidden="true"><?= $check['ok'] ? '✓' : '!' ?></span>
                    <span class="check-body">
                        <strong><?= h($check['label']) ?></strong>
                        <small><?= h($check['detail']) ?></small>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="hint">
            หน้านี้เป็นหน้าตัวอย่างจาก <code>index.php</code> — แก้ไขได้ตามต้องการ
            ไฟล์ที่อัปโหลดจะถูกเก็บแยกใน <code>www/files/</code>
        </p>
    </section>
</div>

<section class="card" id="files">
    <div class="card-head">
        <h2>ไฟล์ที่นำเข้าแล้ว</h2>
        <a class="btn tiny" href="upload.php">＋ เพิ่มไฟล์</a>
    </div>

    <?php if ($fileCount === 0): ?>
        <div class="empty">
            <div class="empty-icon" aria-hidden="true">⌁</div>
            <strong>ยังไม่มีไฟล์ที่นำเข้า</strong>
            <p>อัปโหลดผ่านเบราว์เซอร์ หรือใช้ <code>bash scripts/import.sh</code> จากโฟลเดอร์ Downloads</p>
            <a class="btn primary" href="upload.php">เริ่มนำเข้าไฟล์</a>
        </div>
    <?php else: ?>
        <p class="hint file-summary">
            ทั้งหมด <?= h((string) $fileCount) ?> ไฟล์ · <?= h(format_bytes($filesTotalSize)) ?>
            · เก็บที่ <code>www/files/</code>
        </p>
        <ul class="file-list">
            <?php foreach ($files as $file): ?>
                <?php
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $extensionLabel = preg_match('/^[a-z0-9]{1,5}$/', $extension) ? strtoupper($extension) : 'FILE';
                ?>
                <li>
                    <span class="file-icon<?= $extension === 'php' ? ' php' : '' ?>" aria-hidden="true"><?= h($extensionLabel) ?></span>
                    <a class="file-name" href="<?= h($file['url']) ?>" target="_blank" rel="noreferrer">
                        <?= h($file['name']) ?>
                    </a>
                    <span class="file-meta"><?= h(format_bytes($file['size'])) ?> · <?= h(date('d/m/Y H:i', $file['mtime'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card" id="commands">
    <div class="card-head">
        <h2>คำสั่งจัดการเซิร์ฟเวอร์</h2>
        <span class="tag">TERMUX</span>
    </div>
    <p class="hint">รันจากโฟลเดอร์โปรเจกต์ใน Termux (เช่น <code>~/android-php-server</code>)</p>
    <div class="command-grid">
        <div class="command">
            <span>ตรวจสอบสถานะ</span>
            <code>bash scripts/server.sh status</code>
        </div>
        <div class="command">
            <span>หยุดเซิร์ฟเวอร์</span>
            <code>bash scripts/server.sh stop</code>
        </div>
        <div class="command">
            <span>เปิดใหม่</span>
            <code>bash scripts/server.sh restart</code>
        </div>
        <div class="command">
            <span>ดู log ล่าสุด</span>
            <code>bash scripts/server.sh logs</code>
        </div>
    </div>
</section>

<?php page_end(); ?>
