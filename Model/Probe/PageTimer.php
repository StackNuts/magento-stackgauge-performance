<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Probe;

/**
 * Times one URL from the server it runs on, measuring time to first byte (curl's start-of-transfer
 * time, which includes DNS/connect/TLS/server think time but not body download) and reading the
 * full-page-cache verdict from response headers when the store exposes one.
 */
class PageTimer
{
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TOTAL_TIMEOUT_SECONDS = 10;

    public const CACHE_HIT = 'hit';
    public const CACHE_MISS = 'miss';
    public const CACHE_UNKNOWN = 'unknown';

    /**
     * @return array{status: int, ttfb_ms: int, total_ms: int, cache: string}
     *     status 0 means no HTTP response (connection failure or timeout).
     */
    public function time(string $url): array
    {
        $headers = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'StackGauge-PageSpeed/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $ttfb = (float)curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME);
        $total = (float)curl_getinfo($handle, CURLINFO_TOTAL_TIME);

        if ($body === false || $status === 0) {
            return ['status' => 0, 'ttfb_ms' => 0, 'total_ms' => 0, 'cache' => self::CACHE_UNKNOWN];
        }

        return [
            'status' => $status,
            'ttfb_ms' => (int)round($ttfb * 1000),
            'total_ms' => (int)round($total * 1000),
            'cache' => $this->cacheVerdict($headers),
        ];
    }

    /**
     * @param array<string, string> $headers Lower-cased header names.
     */
    private function cacheVerdict(array $headers): string
    {
        $debug = strtolower($headers['x-magento-cache-debug'] ?? '');
        if ($debug !== '') {
            return str_contains($debug, 'hit') ? self::CACHE_HIT : self::CACHE_MISS;
        }

        // Varnish/CDN setups without the debug header still expose Age or X-Cache.
        if (isset($headers['age']) && (int)$headers['age'] > 0) {
            return self::CACHE_HIT;
        }
        if (isset($headers['x-cache'])) {
            return str_contains(strtolower($headers['x-cache']), 'hit') ? self::CACHE_HIT : self::CACHE_MISS;
        }

        return self::CACHE_UNKNOWN;
    }
}
