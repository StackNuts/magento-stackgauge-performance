<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Runtime;

/**
 * Snapshots PHP runtime state from inside the process that serves storefront traffic.
 *
 * This has to run in a web request, not in StackGauge's cron-driven reporters: cron runs under
 * the CLI SAPI, which has its own php.ini and (usually) its own, disabled or empty OPcache -
 * reading it there says nothing about the FPM pool actually serving customers.
 */
class WebRuntimeCollector
{
    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'collected_at' => time(),
            'sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'memory_limit' => (string)ini_get('memory_limit'),
            'opcache' => $this->opcache(),
            'realpath_cache' => [
                'used_bytes' => realpath_cache_size(),
                'limit_bytes' => $this->iniBytes((string)ini_get('realpath_cache_size')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null Null when OPcache is absent, disabled, or its API is restricted.
     */
    private function opcache(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }

        $status = @opcache_get_status(false);
        if (!is_array($status) || empty($status['opcache_enabled'])) {
            return null;
        }

        $memory = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];
        $strings = $status['interned_strings_usage'] ?? [];

        $total = (float)($memory['used_memory'] ?? 0) + (float)($memory['free_memory'] ?? 0)
            + (float)($memory['wasted_memory'] ?? 0);
        $stringBuffer = (float)($strings['buffer_size'] ?? 0);

        return [
            'hit_rate' => round((float)($stats['opcache_hit_rate'] ?? 0), 2),
            'memory_used_pct' => $total > 0
                ? round((((float)$memory['used_memory'] + (float)($memory['wasted_memory'] ?? 0)) / $total) * 100, 2)
                : 0.0,
            'wasted_pct' => round((float)($memory['current_wasted_percentage'] ?? 0), 2),
            'keys_used_pct' => ($stats['max_cached_keys'] ?? 0) > 0
                ? round(((float)$stats['num_cached_keys'] / (float)$stats['max_cached_keys']) * 100, 2)
                : 0.0,
            'interned_strings_used_pct' => $stringBuffer > 0
                ? round(((float)($strings['used_memory'] ?? 0) / $stringBuffer) * 100, 2)
                : 0.0,
            'cached_scripts' => (int)($stats['num_cached_scripts'] ?? 0),
            'oom_restarts' => (int)($stats['oom_restarts'] ?? 0),
            'hash_restarts' => (int)($stats['hash_restarts'] ?? 0),
            'manual_restarts' => (int)($stats['manual_restarts'] ?? 0),
            'cache_full' => !empty($status['cache_full']),
            'validate_timestamps' => (bool)ini_get('opcache.validate_timestamps'),
            'jit_enabled' => !empty($status['jit']['enabled']),
        ];
    }

    /**
     * Converts a php.ini size shorthand ("4096K", "10M", "1G") to bytes.
     *
     * @param string $value
     */
    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (int)$value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
