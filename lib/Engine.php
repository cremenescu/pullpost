<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);

// The download engine (engine/server.py) over HTTP, and the progress files it writes into the
// shared data folder.
final class Engine
{
    /** @return array|null the engine's /health answer, or null when it does not answer */
    public static function health(): ?array
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }
        $r = self::http('GET', '/health', null, 2);
        return $cache = ($r !== null && ($r['ok'] ?? false) ? $r : null);
    }

    public static function fetch(string $code, string $kind): void
    {
        $r = self::http('POST', '/fetch', ['code' => $code, 'kind' => $kind], 5);
        if ($r === null || empty($r['queued'])) {
            throw new UserError(t('engine_off'));
        }
    }

    /** @return array|null data/jobs/<code>.json, as the engine last wrote it */
    public static function job(string $code): ?array
    {
        $raw = @file_get_contents(data_path('jobs/' . $code . '.json'));
        $job = $raw === false ? null : json_decode($raw, true);
        return is_array($job) ? $job : null;
    }

    private static function http(string $method, string $path, ?array $body, int $timeout): ?array
    {
        $ch = curl_init(rtrim((string) cfg('engine_url'), '/') . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($out === false || $status >= 500 || $status === 0) {
            return null;
        }
        $j = json_decode((string) $out, true);
        return is_array($j) ? $j : null;
    }
}
