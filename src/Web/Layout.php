<?php
declare(strict_types=1);

namespace Sigil\Web;

use Sigil\App;
use Sigil\Util\BasePath;

final class Layout
{
    /**
     * @param array{title: string, nav: string, css: string, body: string} $page
     */
    public static function render(App $app, array $page): void
    {
        $webKey = (string) ($app->config()['app']['web_api_key'] ?? '');
        $title = htmlspecialchars($page['title'], ENT_QUOTES);
        $css = htmlspecialchars($page['css'], ENT_QUOTES);
        $active = static fn(string $nav): string => $page['nav'] === $nav ? ' class="active"' : '';

        $base = BasePath::get();
        $baseMeta = '<meta name="base-path" content="' . htmlspecialchars($base, ENT_QUOTES) . '">';

        $keyMeta = $webKey !== ''
            ? '<meta name="api-key" content="' . htmlspecialchars($webKey, ENT_QUOTES) . '">'
            : '';

        $banner = '';
        if ($webKey === '') {
            $banner = '<div class="banner banner-warn">Web UI needs an API key: run <code>php bin/sigil key:create web</code>, then set <code>WEB_API_KEY</code> in <code>.env</code>.</div>';
        }

        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $title . ' — Sigil</title>
<link rel="icon" href="data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="%230f172a"/><circle cx="16" cy="16" r="8.5" fill="none" stroke="%2334d399" stroke-width="3"/><circle cx="16" cy="16" r="2.5" fill="%2334d399"/></svg>') . '">
' . $baseMeta . '
' . $keyMeta . '
<link rel="stylesheet" href="' . BasePath::url('/assets/css/base.css') . '">
<link rel="stylesheet" href="' . BasePath::url('/assets/css/' . $css) . '">
</head>
<body>
<header class="topbar">
  <a class="brand" href="' . BasePath::url('/') . '">Sigil</a>
  <nav>
    <a href="' . BasePath::url('/') . '"' . $active('studio') . '>Studio</a>
    <a href="' . BasePath::url('/library') . '"' . $active('library') . '>Library</a>
    <a href="' . BasePath::url('/settings') . '"' . $active('settings') . '>Settings</a>
  </nav>
</header>
' . $banner . '
<main>
' . $page['body'] . '
</main>
</body>
</html>';
    }
}
