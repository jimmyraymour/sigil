<?php
declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
$key = (string) ($_GET['key'] ?? '');
$sig = (string) ($_GET['sig'] ?? '');
$exp = (string) ($_GET['exp'] ?? '');

if (!preg_match('/^[a-f0-9-]{36}$/', $id)) {
    http_response_code(404);
    exit('Not found');
}

// Authorization: a valid API key (Bearer header or ?key=) OR a valid
// signed, expiring URL token (issued by the API for web <img> tags).
$auth = $app->auth();
$authorized = false;

$bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (is_string($bearer) && preg_match('/^Bearer\s+(.+)$/i', $bearer, $m) && $auth->isValidKey(trim($m[1]))) {
    $authorized = true;
}
if (!$authorized && $key !== '' && $auth->isValidKey($key)) {
    $authorized = true;
}
if (!$authorized && $sig !== '' && $exp !== '' && ctype_digit($exp) && $app->urlSigner()->verify('render:' . $id, $sig, (int) $exp)) {
    $authorized = true;
}
// Bifrost SSO (Phase 1): an authorized UI session (cookie on .raymour.local)
// may also load renders — same identity the UI pages require. No redirect
// here: <img> requests get the plain 401 below instead of an HTML login page.
if (!$authorized) {
    require dirname(__DIR__) . '/../bifrost/shared/php/BifrostClient.php';
    $authorized = Bifrost::user() !== null;
}

if (!$authorized) {
    http_response_code(401);
    exit('unauthorized');
}

$render = $app->engine()->getRender($id);
if ($render === null || ($render['status'] ?? '') !== 'succeeded' || empty($render['output_path'])) {
    http_response_code(404);
    exit('Not found');
}

$abs = (new Sigil\Render\AssetStore(SIGIL_STORAGE . '/renders'))->open($render['output_path']);
if (!is_file($abs)) {
    http_response_code(404);
    exit('Not found');
}

$mime = match ($render['output_format'] ?? '') {
    'svg' => 'image/svg+xml',
    'jpg', 'jpeg' => 'image/jpeg',
    default => 'image/png',
};
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
if (($render['output_format'] ?? '') === 'svg') {
    header("Content-Security-Policy: default-src 'none'");
}
header('Content-Length: ' . (string) filesize($abs));
readfile($abs);
