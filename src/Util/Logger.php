<?php
declare(strict_types=1);

namespace Sigil\Util;

/**
 * File-based logger. Writes to storage/logs/app-YYYY-MM-DD.log
 * Format: [ISO8601] [LEVEL] [module] message {context_json}
 */
final class Logger
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function debug(string $module, string $message, array $context = []): void
    {
        $this->write('DEBUG', $module, $message, $context);
    }

    public function info(string $module, string $message, array $context = []): void
    {
        $this->write('INFO', $module, $message, $context);
    }

    public function warn(string $module, string $message, array $context = []): void
    {
        $this->write('WARN', $module, $message, $context);
    }

    public function error(string $module, string $message, array $context = []): void
    {
        $this->write('ERROR', $module, $message, $context);
    }

    private function write(string $level, string $module, string $message, array $context = []): void
    {
        $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $line = sprintf("[%s] [%s] [%s] %s%s%s", date('c'), $level, $module, $message, $suffix, PHP_EOL);
        $file = $this->dir . '/app-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
