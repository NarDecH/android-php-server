<?php
declare(strict_types=1);

require __DIR__ . '/_lib.php';
start_session();

$stateDir = rtrim((string) (getenv('PHP_SERVER_STATE_DIR') ?: ((getenv('HOME') ?: '') . '/.php-server')), '/');
$configFile = $stateDir . '/config.env';
$backupFile = $stateDir . '/config.env.bak';
$resultFile = $stateDir . '/settings-result.txt';
$applyScript = dirname(__DIR__) . '/scripts/apply-settings.sh';

$pin = trim((string) getenv('UPLOAD_PIN'));
$pinEnabled = $pin !== '';
$csrf = csrf_token();

/**
 * อ่าน config.env แบบ KEY=VALUE (ตัด quote ครอบค่าให้)
 *
 * @return array<string, string>
 */
function settings_read_config(string $file): array
{
    $values = [];

    if (!is_file($file)) {
        return $values;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            continue;
        }
        $values[$key] = trim(trim($value), "\"'");
    }

    return $values;
}

/**
 * เขียนค่ากลับลง config.env แบบ atomic (คงคอมเมนต์และคีย์อื่นไว้)
 */
function settings_write_config(string $file, array $changes): bool
{
    $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $written = [];
    $result = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        $matched = false;

        foreach ($changes as $key => $value) {
            if (preg_match('/^' . preg_quote($key, '/') . '\s*=/', $trimmed) === 1) {
                $matched = true;

                // เจอคีย์ซ้ำจากไฟล์เดิม: เขียนครั้งเดียวแล้วทิ้งบรรทัดที่เหลือ
                if (isset($written[$key])) {
                    continue 2;
                }

                $result[] = $key . '=' . $value;
                $written[$key] = true;
                break;
            }
        }

        if (!$matched) {
            $result[] = $line;
        }
    }

    foreach ($changes as $key => $value) {
        if (!isset($written[$key])) {
            $result[] = $key . '=' . $value;
        }
    }

    $tmp = $file . '.tmp';
    $payload = implode("\n", $result) . "\n";

    if (file_put_contents($tmp, $payload) === false) {
        return false;
    }

    @chmod($tmp, 0600);

    if (!@rename($tmp, $file)) {
        @unlink($tmp);

        return false;
    }

    @chmod($file, 0600);

    return true;
}

function settings_last_result(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = trim((string) file_get_contents($file));
    if ($raw === '') {
        return [];
    }

    $parts = explode('|', $raw);

    return [
        'status' => $parts[0] ?? '',
        'port' => $parts[1] ?? '',
        'time' => $parts[2] ?? '',
    ];
}

$config = settings_read_config($configFile);
$currentPort = (int) ($config['PORT'] ?? 8080);
$currentHost = $config['HOST'] ?? '127.0.0.1';
$errors = [];
$applying = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!csrf_is_valid($token)) {
        $errors[] = 'CSRF token ไม่ถูกต้อง — รีเฟรชหน้าแล้วลองใหม่';
    }

    $portRaw = trim((string) ($_POST['port'] ?? ''));
    $newPinRaw = (string) ($_POST['new_pin'] ?? '');
    $disablePin = isset($_POST['disable_pin']);

    if ($portRaw === '' || preg_match('/^[0-9]{1,5}$/', $portRaw) !== 1) {
        $errors[] = 'พอร์ตต้องเป็นตัวเลข 1024–65535';
    } else {
        $newPort = (int) $portRaw;
        if ($newPort < 1024 || $newPort > 65535) {
            $errors[] = 'พอร์ตต้องอยู่ระหว่าง 1024–65535 (ต่ำกว่านี้อาจต้องใช้สิทธิ์พิเศษ)';
        }
    }

    $newPin = $pin;
    if ($disablePin) {
        $newPin = '';
    } elseif (trim($newPinRaw) !== '') {
        $newPin = trim($newPinRaw);
        if (preg_match('/^[A-Za-z0-9._-]{4,64}$/', $newPin) !== 1) {
            $errors[] = 'PIN ใช้ได้เฉพาะ A–Z, a–z, 0–9 และ . _ - ความยาว 4–64 ตัว';
        }
    }

    $hostRaw = (string) ($_POST['host'] ?? $currentHost);
    $newHost = in_array($hostRaw, ['127.0.0.1', '0.0.0.0'], true) ? $hostRaw : $currentHost;

    if ($newHost === '0.0.0.0' && $newPin === '') {
        $errors[] = 'เปิดโหมด LAN (0.0.0.0) ไม่ได้ถ้าไม่มี PIN — ตั้ง PIN ก่อน';
    }

    if (!$errors) {
        $changes = [];

        if ($newHost !== $currentHost) {
            $changes['HOST'] = $newHost;
        }
        if ($newPort !== $currentPort) {
            $changes['PORT'] = (string) $newPort;
        }
        if ($newPin !== $pin) {
            $changes['UPLOAD_PIN'] = $newPin;
        }

        if (!$changes) {
            flash_set('warning', 'ไม่มีการเปลี่ยนแปลง');
            redirect_to('settings.php');
        }

        if (!is_file($configFile)) {
            $errors[] = 'ไม่พบไฟล์ตั้งค่า: ' . $configFile;
        } elseif (!@copy($configFile, $backupFile)) {
            $errors[] = 'สำรองไฟล์ตั้งค่าไม่สำเร็จ — ยกเลิกเพื่อความปลอดภัย';
        } elseif (!settings_write_config($configFile, $changes)) {
            $errors[] = 'เขียนไฟล์ตั้งค่าไม่สำเร็จ';
        } else {
            // สั่งรีสตาร์ตแบบ detached เพื่อให้ response นี้กลับไปถึงเบราว์เซอร์ก่อน
            $command = 'nohup bash ' . escapeshellarg($applyScript) . ' '
                . escapeshellarg((string) $newPort) . ' </dev/null >>/dev/null 2>&1 &';
            @exec($command);

            $applying = [
                'port' => $newPort,
                'pin_changed' => array_key_exists('UPLOAD_PIN', $changes),
                'host' => $currentHost,
            ];
        }
    }
}

$lastResult = settings_last_result($resultFile);
$hostForUrl = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:' . $currentPort);
$hostOnly = preg_replace('/:\d+$/', '', $hostForUrl) ?: '127.0.0.1';

page_start('ตั้งค่า', 'settings');
?>
<div class="page-head">
    <div>
        <p class="eyebrow">SETTINGS</p>
        <h1>ตั้งค่าเซิร์ฟเวอร์</h1>
        <p class="sub">แก้พอร์ตและ PIN ได้จากหน้านี้ ไม่ต้องเข้าไปแก้ไฟล์ใน Termux</p>
    </div>
</div>

<?php if ($applying !== null): ?>
    <section class="card">
        <div class="card-head">
            <h2>กำลังรีสตาร์ตเซิร์ฟเวอร์</h2>
            <span class="tag">~5 วินาที</span>
        </div>
        <p class="hint">
            บันทึกค่าตั้งใหม่แล้ว ระบบจะรีสตาร์ตให้อัตโนมัติ
            หากพอร์ตใหม่ไม่ตอบสนอง ระบบจะย้อนกลับค่าเดิมให้เอง
        </p>
        <p class="hint">
            ที่อยู่ใหม่:
            <code>http://<?= h($hostOnly) ?>:<?= h((string) $applying['port']) ?>/settings.php</code>
        </p>
        <?php if ($applying['pin_changed']): ?>
            <div class="alert alert-warning" role="status">
                เปลี่ยน PIN แล้ว — ต้องล็อกอินใหม่ด้วย PIN ใหม่หลังรีสตาร์ต
            </div>
        <?php endif; ?>
        <div class="form-actions">
            <a class="btn primary" href="<?= h('http://' . $hostOnly . ':' . $applying['port'] . '/settings.php') ?>">เปิดหน้าตั้งค่าใหม่</a>
            <a class="btn ghost" href="<?= h('http://' . $hostOnly . ':' . $applying['port'] . '/') ?>">ไปหน้าแรก</a>
        </div>
        <p class="hint small">หน้านี้จะพาไปที่อยู่ใหม่อัตโนมัติใน 6 วินาที</p>
    </section>
    <meta http-equiv="refresh" content="6;url=<?= h('http://' . $hostOnly . ':' . $applying['port'] . '/settings.php') ?>">
    <?php page_end(); ?>
    <?php exit; ?>
<?php endif; ?>

<?php render_flashes(); ?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($lastResult && ($lastResult['status'] ?? '') !== ''): ?>
    <div class="alert alert-<?= $lastResult['status'] === 'ok' ? 'success' : ($lastResult['status'] === 'rollback' ? 'warning' : 'error') ?>" role="status">
        <?php if ($lastResult['status'] === 'ok'): ?>
            รีสตาร์ตล่าสุดสำเร็จ — พอร์ต <?= h($lastResult['port']) ?> (<?= h($lastResult['time']) ?>)
        <?php elseif ($lastResult['status'] === 'rollback'): ?>
            พอร์ตใหม่ไม่ตอบสนอง ระบบย้อนกลับไปพอร์ต <?= h($lastResult['port']) ?> แล้ว (<?= h($lastResult['time']) ?>)
        <?php else: ?>
            รีสตาร์ตล่าสุดล้มเหลว (<?= h($lastResult['time']) ?>) — ตรวจ <code>~/.php-server/settings.log</code>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="grid two">
    <section class="card">
        <div class="card-head">
            <h2>ค่าที่ใช้อยู่</h2>
            <span class="tag <?= $pinEnabled ? 'ok' : '' ?>"><?= $pinEnabled ? 'PIN เปิด' : 'ไม่มี PIN' ?></span>
        </div>
        <dl class="kv">
            <div>
                <dt>โฮสต์</dt>
                <dd><code><?= h($currentHost) ?></code></dd>
            </div>
            <div>
                <dt>พอร์ต</dt>
                <dd><code><?= h((string) $currentPort) ?></code></dd>
            </div>
            <div>
                <dt>PIN</dt>
                <dd><?= $pinEnabled ? 'ตั้งไว้แล้ว (' . h((string) strlen($pin)) . ' ตัว)' : 'ยังไม่ได้ตั้ง — เปิดให้ใครก็ได้ในเครื่องนี้' ?></dd>
            </div>
            <div>
                <dt>ไฟล์ตั้งค่า</dt>
                <dd><code><?= h($configFile) ?></code></dd>
            </div>
        </dl>
        <p class="hint small">
            โหมด LAN เปิดได้เฉพาะเมื่อมี PIN เพื่อไม่ให้ใครในวง Wi-Fi เข้าไฟล์ได้โดยไม่ต้องล็อกอิน
        </p>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>แก้ไขค่า</h2>
            <span class="tag">รีสตาร์ตอัตโนมัติ</span>
        </div>
        <form class="auth-form settings-form" method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save">

            <label for="host">การเข้าถึง</label>
            <select id="host" name="host">
                <option value="127.0.0.1"<?= $currentHost === '127.0.0.1' ? ' selected' : '' ?>>เฉพาะเครื่องนี้ (127.0.0.1)</option>
                <option value="0.0.0.0"<?= $currentHost === '0.0.0.0' ? ' selected' : '' ?>>ให้เครื่องอื่นใน Wi-Fi เข้าถึง (0.0.0.0)</option>
            </select>

            <label for="port">พอร์ต (1024–65535)</label>
            <input id="port" name="port" type="text" inputmode="numeric" pattern="[0-9]{1,5}"
                   value="<?= h((string) $currentPort) ?>" required>

            <label for="new_pin">PIN ใหม่ (เว้นว่าง = คงค่าเดิม)</label>
            <input id="new_pin" name="new_pin" type="text" autocomplete="new-password"
                   placeholder="อย่างน้อย 4 ตัวอักษร">

            <label class="check-row">
                <input type="checkbox" name="disable_pin" value="1">
                <span>ปิด PIN (เปิดให้ทุกคนในเครื่องนี้ใช้ได้)</span>
            </label>

            <p class="hint small">
                PIN ใช้ได้เฉพาะ A–Z, a–z, 0–9 และ . _ - ยาว 4–64 ตัว
                เมื่อบันทึกแล้วเซิร์ฟเวอร์จะรีสตาร์ตและ session เดิมจะใช้ไม่ได้
            </p>

            <div class="form-actions">
                <button class="btn primary" type="submit">บันทึกและรีสตาร์ต</button>
            </div>
        </form>
    </section>
</div>

<section class="card">
    <div class="card-head">
        <h2>ถ้ารีสตาร์ตไม่ขึ้น</h2>
        <span class="tag">TERMUX</span>
    </div>
    <p class="hint">
        ระบบจะย้อนกลับค่าที่สำรองไว้ให้อัตโนมัติ ถ้ายังไม่ขึ้นให้สั่งเองใน Termux:
    </p>
    <pre><code>bash scripts/server.sh status
bash scripts/server.sh restart
tail -n 40 ~/.php-server/settings.log</code></pre>
</section>
<?php
page_end();
