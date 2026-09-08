<?php
declare(strict_types=1);

namespace Sigil\Util;

/**
 * Thin cURL wrapper. No Guzzle, no SDKs — just enough to talk to
 * Ollama, OpenRouter, Recraft and Ideogram.
 */
final class HttpClient
{
    private const USER_AGENT = 'Sigil/1.0';

    /**
     * @return array{status: int, body: string, error: ?string}
     */
    public static function postJson(string $url, array $body, array $headers = [], int $timeout = 60): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], self::headersList($headers)),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $raw === false ? '' : $raw, 'error' => $err === '' ? null : $err];
    }

    /**
     * GET a URL and return the raw body. Throws on HTTP >= 400 or network error.
     */
    public static function get(string $url, array $headers = [], int $timeout = 60): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => self::headersList($headers),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $status >= 400) {
            throw new \RuntimeException('GET ' . $url . ' failed (HTTP ' . $status . ')' . ($err ? ': ' . $err : ''));
        }
        return $raw;
    }

    /**
     * HEAD-style probe: returns the HTTP status code without throwing.
     * Used by the health check (some endpoints only accept GET).
     */
    public static function getStatus(string $url, array $headers = [], int $timeout = 10): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_HTTPHEADER => self::headersList($headers),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status;
    }

    /**
     * Download a URL to a local file. Throws on HTTP >= 400 or network error.
     */
    public static function download(string $url, string $dest, array $headers = [], int $timeout = 120): void
    {
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open destination: ' . $dest);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_HTTPHEADER => self::headersList($headers),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($status >= 400) {
            @unlink($dest);
            throw new \RuntimeException('Download failed (HTTP ' . $status . ')' . ($err ? ': ' . $err : ''));
        }
    }

    private static function headersList(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = is_int($k) ? $v : $k . ': ' . $v;
        }
        return $out;
    }
}
