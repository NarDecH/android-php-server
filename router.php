<?php
declare(strict_types=1);

/**
 * Router สำหรับ PHP built-in server
 * - บล็อกไฟล์ซ่อนและไฟล์ภายใน
 * - ปิด directory listing ของ www/files
 * - กัน path traversal ด้วย realpath()
 * - ให้ /healthz สำหรับตรวจสอบสถานะจาก server.sh
 */

$root = realpath(__DIR__ . '/www');
if ($root === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ไม่พบโฟลเดอร์ www/';
    return true;
}

$root = rtrim($root, DIRECTORY_SEPARATOR);
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($rawPath) ? rawurldecode($rawPath) : '/';
$uri = '/' . ltrim(str_replace('\\', '/', $uri), '/');

function router_not_found(string $message): bool
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<HTML
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>404 · PHP Server</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#070d19;color:#e8eefb;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
.box{max-width:460px;margin:24px;padding:32px;border:1px solid #1f3150;border-radius:18px;background:#0f1a2e;text-align:center}
.code{color:#34d399;font:700 12px ui-monospace,monospace;letter-spacing:.18em}
h1{margin:10px 0 8px;font-size:24px}
p{margin:0 0 20px;color:#8ca0c0;font-size:14px;line-height:1.6}
a{color:#04130f;background:linear-gradient(135deg,#34d399,#22d3ee);padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:700;font-size:13px}
</style>
</head>
<body>
<div class="box">
  <div class="code">404 NOT FOUND</div>
  <h1>ไม่พบหน้านี้</h1>
  <p>{$safeMessage}</p>
  <a href="/">กลับหน้าแรก</a>
</div>
</body>
</html>
HTML;

    return true;
}

if ($uri === '/healthz') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'ok';
    return true;
}

// เมื่อตั้ง UPLOAD_PIN ให้ป้องกันทั้งเว็บ (ยกเว้นหน้า login และ assets)
$pin = trim((string) getenv('UPLOAD_PIN'));
if ($pin !== '' && $uri !== '/upload.php' && !str_starts_with($uri, '/assets/')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
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

    $pinFingerprint = hash('sha256', $pin);
    $isAuthed = !empty($_SESSION['upload_authed'])
        && is_string($_SESSION['auth_fingerprint'] ?? null)
        && hash_equals($pinFingerprint, $_SESSION['auth_fingerprint']);

    if (!$isAuthed) {
        header('Location: /upload.php', true, 302);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        exit;
    }
}

if (str_contains($uri, "\0") || preg_match('#(^|/)\.#', $uri)) {
    return router_not_found('ไฟล์หรือโฟลเดอร์นี้ถูกซ่อนไว้');
}

if ($uri === '/_lib.php') {
    return router_not_found('ไฟล์นี้เป็นไฟล์ภายในของระบบ');
}

$candidate = realpath($root . $uri);
$isInsideRoot = $candidate !== false
    && ($candidate === $root || str_starts_with($candidate, $root . DIRECTORY_SEPARATOR));

if (!$isInsideRoot) {
    return router_not_found('ไม่พบไฟล์หรือโฟลเดอร์ที่ต้องการ');
}

if (is_dir($candidate)) {
    if (is_file($candidate . DIRECTORY_SEPARATOR . 'index.php') || is_file($candidate . DIRECTORY_SEPARATOR . 'index.html')) {
        return false;
    }

    return router_not_found('โฟลเดอร์นี้ไม่มี index.php หรือ index.html (ปิด directory listing เพื่อความปลอดภัย)');
}

// ให้ PHP built-in server เสิร์ฟไฟล์จริง (PHP จะถูก execute ตามปกติ)
return false;
