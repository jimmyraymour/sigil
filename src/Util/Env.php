<?php
declare(strict_types=1);

namespace Sigil\Util;

/**
 * Tiny .env loader + typed getters. Values are exposed via putenv/getenv
 * so they also work in CLI scripts and PHP-FPM.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        // Shared mod_php worker guard (tracker 3269): putenv() below leaks into
        // whichever sibling app the Apache worker serves next. Snapshot the
        // process env and restore it on shutdown.
        static $guarded = false;
        if (!$guarded) {
            $guarded = true;
            $envAtStart = getenv();
            if (is_array($envAtStart)) {
                register_shutdown_function(static function () use ($envAtStart): void {
                    $now = getenv();
                    if (!is_array($now)) {
                        return;
                    }
                    foreach ($now as $key => $value) {
                        if (isset($envAtStart[$key])) {
                            if ($envAtStart[$key] !== $value) {
                                putenv($key . '=' . $envAtStart[$key]);
                            }
                        } else {
                            putenv($key);
                        }
                    }
                });
            }
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $len = strlen($value);
            if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v === false) {
            $v = $_ENV[$key] ?? null;
        }
        return ($v !== null && $v !== '') ? $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }
}
