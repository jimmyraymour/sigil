<?php
declare(strict_types=1);

use Sigil\Web\Pages\HomePage;
use Sigil\Web\Pages\LibraryPage;
use Sigil\Web\Pages\SettingsPage;
use Sigil\Util\BasePath;

$app = require __DIR__ . '/../bootstrap.php';

// Bifrost SSO (Phase 1): every UI page requires a signed-in identity. The
// browser carries the bifrost_session cookie (.raymour.local) and the shared
// client introspects it server-side. The REST API keeps its Bearer-key auth;
// render.php accepts either.
require dirname(__DIR__) . '/../bifrost/shared/php/BifrostClient.php';
Bifrost::requireLogin();

// Sigil may be mounted in a subdirectory (XAMPP serves it at /sigil/), so the
// mount point is stripped before routing.
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = BasePath::strip($path === false || $path === null ? '/' : $path);

switch ($path) {
    case '/':
        (new HomePage($app))->render();
        break;
    case '/library':
        (new LibraryPage($app))->render();
        break;
    case '/settings':
        (new SettingsPage($app))->render();
        break;
    default:
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Not found — Sigil</title>'
            . '<body style="font:16px system-ui;background:#0f172a;color:#e2e8f0;padding:48px">'
            . '<h1 style="margin:0 0 8px">404</h1><p style="color:#94a3b8">No page at <code>'
            . htmlspecialchars($path, ENT_QUOTES) . '</code>.</p>'
            . '<p><a style="color:#2dd4bf" href="' . BasePath::url('/') . '">Back to the studio</a></p>';
        break;
}
