<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';
start_session();

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$pin = trim((string) getenv('UPLOAD_PIN'));
$pinEnabled = $pin !== '';
$pinFingerprint = $pinEnabled ? hash('sha256', $pin) : '';
$isAuthed = !$pinEnabled || (
    !empty($_SESSION['upload_authed'])
    && is_string($_SESSION['auth_fingerprint'] ?? null)
    && hash_equals($pinFingerprint, $_SESSION['auth_fingerprint'])
);
$isLocal = is_local_request();
$csrf = csrf_token();
$maxFileUpload = ini_bytes('upload_max_filesize');
$maxPostSize = ini_bytes('post_max_size');
$maxFileUploads = (int) ini_get('max_file_uploads');

/**
 * @param array<string, mixed> $payload
 */
function upload_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function upload_error_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'ไฟล์ใหญ่เกิน upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'ไฟล์ใหญ่เกินข้อจำกัดของฟอร์ม',
        UPLOAD_ERR_PARTIAL => 'อัปโหลดไม่ครบ (การเชื่อมต่อขาด)',
        UPLOAD_ERR_NO_FILE => 'ไม่มีไฟล์',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP ไม่มีโฟลเดอร์ชั่วคราว',
        UPLOAD_ERR_CANT_WRITE => 'เขียนไฟล์ลงดิสก์ไม่ได้',
        UPLOAD_ERR_EXTENSION => 'ส่วนขยาย PHP สั่งหยุดการอัปโหลด',
        default => 'เกิดข้อผิดพลาดระหว่างอัปโหลด',
    };
}

function safe_upload_name(string $name): ?string
{
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);

    if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.')) {
        return null;
    }

    if (strlen($name) > 200) {
        return null;
    }

    return $name;
}

function unique_destination(string $directory, string $name): string
{
    $target = $directory . DIRECTORY_SEPARATOR . $name;
    if (!file_exists($target)) {
        return $target;
    }

    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $suffix = $extension !== '' ? '.' . $extension : '';

    for ($i = 1; $i <= 999; $i++) {
        $candidate = $directory . DIRECTORY_SEPARATOR . $base . '-' . $i . $suffix;
        if (!file_exists($candidate)) {
            return $candidate;
        }
    }

    return $directory . DIRECTORY_SEPARATOR . $base . '-' . bin2hex(random_bytes(4)) . $suffix;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    // PHP จะล้าง $_POST/$_FILES ทั้งหมดเมื่อข้อมูลเกิน post_max_size
    // ดังนั้นต้องตรวจจาก Content-Length ก่อนอ่าน action/CSRF
    if ($maxPostSize > 0 && $contentLength > $maxPostSize && $_POST === [] && $_FILES === []) {
        $message = 'ข้อมูลที่ส่งใหญ่เกิน post_max_size (' . format_bytes($maxPostSize) . ') — ลดขนาดไฟล์หรือเพิ่มค่าใน config.env';
        if ($isAjax) {
            upload_json(['ok' => false, 'message' => $message]);
        }
        flash_set('error', $message);
        redirect_to('upload.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        if ($isAjax && $action === 'upload') {
            upload_json(['ok' => false, 'message' => 'CSRF token ไม่ถูกต้อง กรุณารีเฟรชหน้า']);
        }
        flash_set('error', 'คำขอไม่ถูกต้อง (CSRF token) กรุณาลองใหม่');
        redirect_to('upload.php');
    }

    if ($action === 'login') {
        if (!$pinEnabled) {
            redirect_to('upload.php');
        }

        if (hash_equals($pin, (string) ($_POST['pin'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['upload_authed'] = true;
            $_SESSION['auth_fingerprint'] = $pinFingerprint;
            flash_set('success', 'ปลดล็อกแล้ว — เริ่มนำเข้าไฟล์ได้');
            redirect_to('upload.php');
        }

        usleep(400000);
        flash_set('error', 'PIN ไม่ถูกต้อง');
        redirect_to('upload.php');
    }

    if (!$isAuthed) {
        flash_set('error', 'กรุณาใส่ PIN ก่อนใช้งาน');
        redirect_to('upload.php');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect_to('upload.php');
    }

    if ($action === 'upload') {
        if (!ensure_files_dir()) {
            $message = 'โฟลเดอร์ www/files ไม่พร้อมใช้งาน (ตรวจสอบสิทธิ์การเขียน)';
            if ($isAjax) {
                upload_json(['ok' => false, 'message' => $message]);
            }
            flash_set('error', $message);
            redirect_to('upload.php');
        }

        $posted = $_FILES['files'] ?? null;
        if (!is_array($posted) || !isset($posted['name'])) {
            $message = 'ยังไม่ได้เลือกไฟล์';
            if ($isAjax) {
                upload_json(['ok' => false, 'message' => $message]);
            }
            flash_set('warning', $message);
            redirect_to('upload.php');
        }

        $names = is_array($posted['name']) ? $posted['name'] : [$posted['name']];
        $tmpNames = is_array($posted['tmp_name'] ?? null) ? $posted['tmp_name'] : [$posted['tmp_name'] ?? ''];
        $errorCodes = is_array($posted['error'] ?? null) ? $posted['error'] : [$posted['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = is_array($posted['size'] ?? null) ? $posted['size'] : [$posted['size'] ?? 0];

        $uploaded = 0;
        $failed = 0;
        $errors = [];
        $destinationDirectory = files_path();

        foreach ($names as $index => $rawName) {
            $errorCode = (int) ($errorCodes[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($errorCode !== UPLOAD_ERR_OK) {
                $failed++;
                $errors[] = (string) $rawName . ': ' . upload_error_text($errorCode);
                continue;
            }

            $name = safe_upload_name((string) $rawName);
            if ($name === null) {
                $failed++;
                $errors[] = 'ชื่อไฟล์ไม่ถูกต้องหรือเป็นไฟล์ซ่อน';
                continue;
            }

            $tmpName = (string) ($tmpNames[$index] ?? '');
            if (!is_uploaded_file($tmpName)) {
                $failed++;
                $errors[] = $name . ': ไม่พบไฟล์ชั่วคราว';
                continue;
            }

            $size = (int) ($sizes[$index] ?? 0);
            if ($maxFileUpload > 0 && $size > $maxFileUpload) {
                $failed++;
                $errors[] = $name . ': ใหญ่เกิน ' . format_bytes($maxFileUpload);
                continue;
            }

            $target = unique_destination($destinationDirectory, $name);
            if (!move_uploaded_file($tmpName, $target)) {
                $failed++;
                $errors[] = $name . ': ย้ายไฟล์ไม่สำเร็จ';
                continue;
            }

            @chmod($target, 0644);
            $uploaded++;
        }

        if ($uploaded > 0) {
            flash_set('success', 'อัปโหลดสำเร็จ ' . $uploaded . ' ไฟล์');
        }
        if ($failed > 0) {
            $detail = $errors !== [] ? ' — ' . implode(' · ', array_slice($errors, 0, 3)) : '';
            flash_set('warning', 'อัปโหลดไม่สำเร็จ ' . $failed . ' ไฟล์' . $detail);
        }
        if ($uploaded === 0 && $failed === 0) {
            flash_set('warning', 'ยังไม่ได้เลือกไฟล์');
        }

        if ($isAjax) {
            upload_json([
                'ok' => $failed === 0 && $uploaded > 0,
                'uploaded' => $uploaded,
                'failed' => $failed,
                'errors' => $errors,
            ]);
        }

        redirect_to('upload.php#files');
    }

    if ($action === 'delete') {
        $fileName = safe_upload_name((string) ($_POST['file'] ?? ''));
        $root = realpath(files_path());
        $target = $fileName === null ? false : realpath(files_path() . DIRECTORY_SEPARATOR . $fileName);

        if ($fileName === null || $root === false || $target === false || dirname($target) !== $root || !is_file($target)) {
            flash_set('error', 'ไม่พบไฟล์ที่ต้องการลบ');
            redirect_to('upload.php#files');
        }

        if (!unlink($target)) {
            flash_set('error', 'ลบไฟล์ไม่สำเร็จ');
            redirect_to('upload.php#files');
        }

        flash_set('success', 'ลบไฟล์ ' . $fileName . ' แล้ว');
        redirect_to('upload.php#files');
    }

    flash_set('warning', 'ไม่รู้จักคำสั่งนี้');
    redirect_to('upload.php');
}

if (!$isAuthed) {
    page_start('ใส่ PIN', 'upload');
    render_flashes();
    ?>
    <section class="auth-shell">
        <div class="card auth-card">
            <div class="auth-icon" aria-hidden="true">⌑</div>
            <p class="eyebrow">PIN REQUIRED</p>
            <h1>ปลดล็อกการนำเข้าไฟล์</h1>
            <p class="hint">เซิร์ฟเวอร์นี้ตั้ง <code>UPLOAD_PIN</code> ไว้ กรอก PIN เพื่อจัดการไฟล์</p>
            <form method="post" action="upload.php" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="login">
                <label for="pin">PIN</label>
                <input
                    id="pin"
                    name="pin"
                    type="password"
                    inputmode="text"
                    autocomplete="current-password"
                    required
                    autofocus
                    placeholder="••••••"
                >
                <button class="btn primary" type="submit">ปลดล็อก</button>
            </form>
            <p class="hint small">ตั้งค่า PIN ได้ใน <code>~/.php-server/config.env</code> แล้วรีสตาร์ตเซิร์ฟเวอร์</p>
        </div>
    </section>
    <?php
    page_end();
    exit;
}

$files = imported_files();
$filesTotalSize = array_sum(array_column($files, 'size'));
$maxUploadLabel = $maxFileUpload > 0 ? format_bytes($maxFileUpload) : 'ตามค่า PHP';

page_start('นำเข้าไฟล์', 'upload');
?>

<div class="page-head">
    <div>
        <p class="eyebrow">FILE IMPORT</p>
        <h1>นำเข้าไฟล์เข้าเซิร์ฟเวอร์</h1>
        <p class="sub">อัปโหลดได้หลายไฟล์พร้อมกัน ไฟล์จะถูกเก็บใน <code>www/files/</code> และเปิดใช้ได้ทันที</p>
    </div>
    <a class="btn ghost" href="index.php">← กลับหน้าแรก</a>
</div>

<?php if (!$isLocal && !$pinEnabled): ?>
    <div class="alert alert-warning">
        คุณกำลังใช้งานจากอุปกรณ์อื่น และยังไม่ได้ตั้ง PIN — แนะนำให้ตั้ง <code>UPLOAD_PIN</code>
        ก่อนเปิดเซิร์ฟเวอร์ให้เครื่องอื่นเข้าถึง
    </div>
<?php endif; ?>

<?php render_flashes(); ?>

<div class="grid upload-layout">
    <section class="card">
        <div class="card-head">
            <h2>อัปโหลดผ่านเบราว์เซอร์</h2>
            <span class="tag">ไม่เกิน <?= h((string) $maxFileUploads) ?> ไฟล์ / ครั้ง</span>
        </div>

        <form id="upload-form" class="upload-form" method="post" action="upload.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="upload">

            <div class="dropzone" id="dropzone">
                <input class="file-input" id="file-input" type="file" name="files[]" multiple required>
                <label class="dropzone-inner" for="file-input">
                    <span class="drop-icon" aria-hidden="true">⇪</span>
                    <strong>แตะเพื่อเลือกไฟล์</strong>
                    <span>หรือลากไฟล์มาวางที่นี่</span>
                </label>
            </div>

            <p class="hint" id="upload-hint">
                สูงสุด <?= h($maxUploadLabel) ?> ต่อไฟล์
                · <?= h(format_bytes($maxPostSize)) ?> ต่อคำขอ
                · เก็บที่ <code>www/files/</code>
            </p>

            <div class="selected-files" id="selected-files" hidden>
                <strong>ไฟล์ที่เลือก</strong>
                <ul id="selected-files-list"></ul>
            </div>

            <div class="progress" id="upload-progress" hidden>
                <div class="progress-bar" id="upload-progress-bar"></div>
            </div>
            <p class="form-status" id="form-status" role="status" aria-live="polite"></p>

            <div class="form-actions">
                <button class="btn primary" id="upload-button" type="submit">อัปโหลดไฟล์</button>
                <span class="hint small">ไฟล์ชื่อซ้ำจะถูกเปลี่ยนชื่ออัตโนมัติ (-1, -2, …)</span>
            </div>
        </form>
    </section>

    <aside class="stack">
        <section class="card">
            <div class="card-head">
                <h2>นำเข้าจาก Downloads</h2>
                <span class="tag">CLI</span>
            </div>
            <p class="hint">ใช้ได้แม้ไฟล์ใหญ่หรือมีหลายไฟล์ เหมาะกับเว็บทั้งโฟลเดอร์</p>
            <p class="command-label">เปิดเมนูเลือกไฟล์</p>
            <pre class="code-block"><code>bash scripts/import.sh</code></pre>
            <p class="command-label">import ไฟล์หรือ ZIP ตรง ๆ</p>
            <pre class="code-block"><code>bash scripts/import.sh ~/storage/downloads/site.zip</code></pre>
            <p class="hint small">ต้องรัน <code>termux-setup-storage</code> และอนุญาตการเข้าถึงไฟล์ก่อน</p>
        </section>

        <section class="card security-card">
            <div class="card-head">
                <h2>ความปลอดภัย</h2>
                <span class="tag <?= $pinEnabled ? 'ok' : '' ?>"><?= $pinEnabled ? 'PIN เปิด' : 'ไม่มี PIN' ?></span>
            </div>
            <ul class="mini-list">
                <li>ไฟล์ <code>.php</code> ที่นำเข้าจะถูกเรียกทำงานจริง</li>
                <li>ค่าเริ่มต้นเซิร์ฟเวอร์ฟังเฉพาะ <code>127.0.0.1</code></li>
                <li>ถ้าเปิดให้เครื่องอื่นใน Wi-Fi ใช้ ต้องตั้ง <code>UPLOAD_PIN</code></li>
                <li>เมื่อตั้ง PIN แล้ว ทั้งเว็บและไฟล์จะต้องปลดล็อกก่อนเข้าถึง</li>
                <li>ห้ามเปิดพอร์ตนี้สู่อินเทอร์เน็ต</li>
            </ul>
            <?php if ($pinEnabled): ?>
                <form method="post" action="upload.php" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="btn tiny" type="submit">ล็อกเอาต์</button>
                </form>
            <?php endif; ?>
        </section>
    </aside>
</div>

<section class="card" id="files">
    <div class="card-head">
        <h2>ไฟล์ในเซิร์ฟเวอร์</h2>
        <span class="tag"><?= h((string) count($files)) ?> ไฟล์ · <?= h(format_bytes($filesTotalSize)) ?></span>
    </div>

    <?php if ($files === []): ?>
        <div class="empty">
            <div class="empty-icon" aria-hidden="true">⌁</div>
            <strong>ยังไม่มีไฟล์</strong>
            <p>เลือกไฟล์ด้านบน หรือใช้สคริปต์นำเข้าจาก Downloads</p>
        </div>
    <?php else: ?>
        <p class="hint">คลิกชื่อไฟล์เพื่อเปิดผ่านเซิร์ฟเวอร์ · ไฟล์ PHP จะถูก execute</p>
        <ul class="file-list manage">
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
                    <div class="file-actions">
                        <a class="btn tiny" href="<?= h($file['url']) ?>" target="_blank" rel="noreferrer">เปิด</a>
                        <form
                            method="post"
                            action="upload.php"
                            data-confirm="ลบไฟล์ <?= h($file['name']) ?> ใช่หรือไม่?"
                        >
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="file" value="<?= h($file['name']) ?>">
                            <button class="btn tiny danger" type="submit">ลบ</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php page_end(); ?>
