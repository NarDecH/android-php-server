<?php
declare(strict_types=1);

/**
 * ตัวช่วยที่ใช้ร่วมกันระหว่าง index.php และ upload.php
 * ไฟล์นี้ถูกบล็อกไม่ให้เปิดตรงผ่าน router.php
 */

date_default_timezone_set(getenv('APP_TZ') ?: 'Asia/Bangkok');

const APP_NAME = 'PHP Server on Android';
const APP_VERSION = '1.0.0';
const REPO_URL = 'https://github.com/NarDecH/android-php-server';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_bytes(int|float $bytes, int $precision = 1): string
{
    $bytes = max(0, (float) $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, $index === 0 ? 0 : $precision) . ' ' . $units[$index];
}

/**
 * แปลงค่า ini เช่น 64M, 1G, 512K ให้เป็นจำนวนไบต์
 */
function ini_bytes(string $key): int
{
    $raw = trim((string) ini_get($key));
    if ($raw === '' || $raw === '-1') {
        return 0;
    }

    if (!preg_match('/^(-?\d+(?:\.\d+)?)\s*([kmg])?$/i', $raw, $matches)) {
        return 0;
    }

    $number = (float) $matches[1];
    $unit = strtolower($matches[2] ?? '');

    return (int) round(match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    });
}

function files_path(): string
{
    return __DIR__ . '/files';
}

function ensure_files_dir(): bool
{
    $dir = files_path();

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    return is_writable($dir);
}

/**
 * @return array<int, array{name: string, size: int, mtime: int, url: string}>
 */
function imported_files(): array
{
    $dir = files_path();
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    $entries = scandir($dir);

    foreach ($entries === false ? [] : $entries as $name) {
        if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            continue;
        }

        $files[] = [
            'name' => $name,
            'size' => (int) filesize($path),
            'mtime' => (int) filemtime($path),
            'url' => 'files/' . rawurlencode($name),
        ];
    }

    usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    return $files;
}

function is_local_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function server_base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080');
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = '127.0.0.1';
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    return ($isHttps ? 'https' : 'http') . '://' . $host;
}

/**
 * IPv4 ของเครื่องนี้ที่เครื่องอื่นในวงเดียวกันเข้าถึงได้ (Wi-Fi ก่อน)
 *
 * Termux อ่าน /proc/net/fib_trie ไม่ได้ และมักไม่มีคำสั่ง `ip` ใน PATH
 * จึงใช้ net_get_interfaces() ของ PHP เป็นหลัก แล้วค่อย fallback ไปที่ ifconfig
 *
 * @return array<int, array{ip: string, iface: string}>
 */
function lan_ipv4_networks(): array
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    /** @var array<int, array{0: string, 1: string}> $candidates */
    $candidates = [];

    if (function_exists('net_get_interfaces')) {
        $interfaces = @net_get_interfaces();

        foreach (is_array($interfaces) ? $interfaces : [] as $name => $info) {
            if (empty($info['up'])) {
                continue;
            }

            foreach (($info['unicast'] ?? []) as $address) {
                $ip = is_array($address) ? (string) ($address['address'] ?? '') : '';
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $candidates[] = [(string) $name, $ip];
                }
            }
        }
    }

    if (!$candidates) {
        $output = [];
        @exec('ifconfig 2>/dev/null', $output);

        $interface = '';
        foreach ($output as $line) {
            if (preg_match('/^([A-Za-z0-9_.:-]+)\s/', $line, $matches) === 1) {
                $interface = $matches[1];
            }
            if (preg_match('/\binet (?:addr:)?(\d{1,3}(?:\.\d{1,3}){3})/', $line, $matches) === 1) {
                $candidates[] = [$interface, $matches[1]];
            }
        }
    }

    if (!$candidates) {
        $output = [];
        @exec('ip -4 -o addr show scope global 2>/dev/null', $output);

        foreach ($output as $line) {
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d{1,3}(?:\.\d{1,3}){3})/', $line, $matches) === 1) {
                $candidates[] = [$matches[1], $matches[2]];
            }
        }
    }

    $usable = [];
    foreach ($candidates as [$name, $ip]) {
        if ($ip === '0.0.0.0' || str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
            continue;
        }

        if (preg_match('/^(lo|dummy|p2p|tun|ppp|rmnet|ccmni)/i', $name) === 1) {
            continue;
        }

        $usable[] = [$name, $ip];
    }

    // Wi-Fi มาก่อน แล้วค่อยอินเทอร์เฟซอื่น (hotspot / USB tethering)
    usort($usable, static function (array $a, array $b): int {
        $rank = static function (string $name): int {
            if (preg_match('/^(wlan|swlan)/i', $name) === 1) {
                return 0;
            }

            return preg_match('/^(ap|eth)/i', $name) === 1 ? 1 : 2;
        };

        return $rank($a[0]) <=> $rank($b[0]);
    });

    $networks = [];
    foreach ($usable as [$name, $ip]) {
        if (isset($networks[$ip])) {
            continue;
        }

        $networks[$ip] = ['ip' => $ip, 'iface' => $name];
    }

    return $cached = array_values($networks);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    session_name('PHPSRV');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false,
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_is_valid(mixed $token): bool
{
    $stored = $_SESSION['csrf_token'] ?? '';

    return is_string($stored) && is_string($token) && hash_equals($stored, $token);
}

/**
 * @param 'success'|'warning'|'error' $type
 */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] ??= [];
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * @return array<int, array{type: string, message: string}>
 */
function flash_take(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($messages) ? $messages : [];
}

function render_flashes(): void
{
    foreach (flash_take() as $flash) {
        $type = in_array($flash['type'] ?? '', ['success', 'warning', 'error'], true)
            ? $flash['type']
            : 'warning';

        echo '<div class="alert alert-' . h($type) . '" role="status">'
            . h($flash['message'] ?? '')
            . '</div>';
    }
}

function redirect_to(string $location): void
{
    header('Location: ' . $location, true, 302);
    exit;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
    );
}

function page_start(string $title, string $active = ''): void
{
    send_security_headers();
    $navItems = [
        'home' => ['index.php', 'หน้าแรก'],
        'upload' => ['upload.php', 'นำเข้าไฟล์'],
        'logs' => ['logs.php', 'บันทึก'],
        'settings' => ['settings.php', 'ตั้งค่า'],
    ];
    ?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070d19">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="bg-glow" aria-hidden="true"></div>
<div class="wrap">
    <header class="topbar">
        <a class="brand" href="index.php">
            <span class="brand-mark" aria-hidden="true">&lt;/&gt;</span>
            <span class="brand-text">
                <strong>PHP Server</strong>
                <small>Android · Termux</small>
            </span>
        </a>
        <nav class="nav" aria-label="เมนูหลัก">
            <?php foreach ($navItems as $key => [$href, $label]): ?>
                <a href="<?= h($href) ?>"<?= $active === $key ? ' class="active" aria-current="page"' : '' ?>>
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <span class="status-pill"><span class="dot" aria-hidden="true"></span> กำลังทำงาน</span>
    </header>
    <?php
}

function page_end(array $scripts = []): void
{
    ?>
    <footer class="footer">
        <span><?= h(APP_NAME) ?> v<?= h(APP_VERSION) ?></span>
        <span class="sep" aria-hidden="true">·</span>
        <a href="<?= h(REPO_URL) ?>" target="_blank" rel="noreferrer">GitHub</a>
        <span class="sep" aria-hidden="true">·</span>
        <span>PHP built-in server (ใช้ส่วนตัว/พัฒนาเท่านั้น)</span>
    </footer>
</div>
<?php foreach ($scripts as $script): ?>
<script src="<?= h($script) ?>" defer></script>
<?php endforeach; ?>
<script src="assets/app.js" defer></script>
</body>
</html>
    <?php
}
