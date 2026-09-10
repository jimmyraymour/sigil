<?php
declare(strict_types=1);

namespace Sigil\Util;

/**
 * Where the app lives in the URL space.
 *
 * Sigil was written assuming it owns the domain root, so every link, asset
 * and API call was an absolute path from "/". Dropped into XAMPP's htdocs it
 * is served from http://localhost/sigil/ instead, and every one of those
 * paths pointed at the wrong place — the CSS 404'd, the router matched
 * nothing, and the page came back "Not found".
 *
 * The base path is detected per request, so the same code works at the
 * domain root (nginx, the PHP dev server) and in a subdirectory (XAMPP)
 * with no configuration. Set APP_BASE_PATH in .env to force it.
 *
 * Returns either '' (domain root) or a path with a leading and no trailing
 * slash, e.g. '/sigil'.
 */
final class BasePath
{
    private static ?string $cached = null;

    public static function get(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $configured = trim((string) Env::get('APP_BASE_PATH', ''));
        if ($configured !== '') {
            return self::$cached = '/' . trim($configured, '/');
        }

        return self::$cached = self::detect();
    }

    /** For tests and the CLI, which have no request context. */
    public static function set(string $base): void
    {
        self::$cached = $base === '' ? '' : '/' . trim($base, '/');
    }

    private static function detect(): string
    {
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($uri === '') {
            $uri = '/';
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        // dirname() emits a backslash on Windows PHP when the script sits at
        // the root (dirname('/index.php') === '\\') — normalize its OUTPUT
        // too, or vhost mode detects a bogus '\/' base path.
        $scriptDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($scriptDir === '.' || $scriptDir === '/') {
            $scriptDir = '';
        }

        // Reached directly, e.g. /sigil/public/index.php.
        if ($scriptDir !== '' && ($uri === $scriptDir || str_starts_with($uri, $scriptDir . '/'))) {
            return $scriptDir;
        }

        // Rewritten in from the parent by the project-root .htaccess:
        // the browser asked for /sigil/library, Apache ran /sigil/public/index.php.
        $parent = rtrim(dirname($scriptDir), '/');
        if ($parent !== '' && $parent !== '.' && ($uri === $parent || str_starts_with($uri, $parent . '/'))) {
            return $parent;
        }

        return '';
    }

    /** Absolute URL path for something inside the app. */
    public static function url(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');
        $base = self::get();
        return $base === '' ? $path : $base . rtrim($path, '/');
    }

    /** The request path with the base stripped, always starting with '/'. */
    public static function strip(string $uriPath): string
    {
        $base = self::get();
        if ($base !== '' && str_starts_with($uriPath, $base)) {
            $uriPath = substr($uriPath, strlen($base));
        }
        $uriPath = rtrim($uriPath, '/');
        return $uriPath === '' ? '/' : $uriPath;
    }
}
