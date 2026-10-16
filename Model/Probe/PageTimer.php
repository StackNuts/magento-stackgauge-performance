<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Probe;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Throwable;

/**
 * Times one URL from the server it runs on, measuring time to first byte (curl's start-of-transfer
 * time, which includes DNS/connect/TLS/server think time but not body download) and reading the
 * full-page-cache verdict from response headers when the store exposes one.
 *
 * Built to leave no trace on the store it measures: every request carries PROBE_HEADER, which
 * Plugin\SkipProbeSearchStats uses to keep the search probe out of the store's search-term
 * popularity stats, and requests share one cookie jar under var/ so the cart probe reuses a
 * single session within a run (the jar is dropped once it is from an earlier run, so one run's cart
 * session never leaks into the next).
 */
class PageTimer
{
    public const PROBE_HEADER = 'X-StackGauge-Probe';

    private const COOKIE_JAR = 'stacknuts_stackgaugeperformance/probe_cookies.txt';

    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TOTAL_TIMEOUT_SECONDS = 10;

    /**
     * Probe runs are hourly, so a jar older than this belongs to a previous run and is dropped.
     */
    private const COOKIE_JAR_MAX_AGE_SECONDS = 1800;

    public const CACHE_HIT = 'hit';
    public const CACHE_MISS = 'miss';
    public const CACHE_UNKNOWN = 'unknown';

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

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
            CURLOPT_HTTPHEADER => [self::PROBE_HEADER . ': 1'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $cookieJar = $this->cookieJarPath();
        if ($cookieJar !== null) {
            curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieJar);
        }

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

    /**
     * Absolute path of the shared cookie jar, or null if var/ isn't writable (the probe then just
     * runs without one).
     */
    private function cookieJarPath(): ?string
    {
        try {
            $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $var->create(dirname(self::COOKIE_JAR));
            if ($var->isExist(self::COOKIE_JAR)
                && time() - (int)($var->stat(self::COOKIE_JAR)['mtime'] ?? 0) > self::COOKIE_JAR_MAX_AGE_SECONDS
            ) {
                $var->delete(self::COOKIE_JAR);
            }

            return $var->getAbsolutePath(self::COOKIE_JAR);
        } catch (Throwable) {
            return null;
        }
    }
}
