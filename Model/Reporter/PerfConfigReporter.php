<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ScopeInterface as AppScopeInterface;
use Magento\Framework\App\State;
use Magento\Framework\Indexer\IndexerRegistry;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PerformanceSectionTrait;

/**
 * Audit of the configuration choices that most affect storefront speed: deploy mode, full-page
 * cache backend and TTL, asset merging/bundling/critical CSS, and file-based cache or session
 * storage. Display-only - these are one-off setup decisions, not values that drift hourly.
 * Minification is covered by the security module's config hygiene reporter, not repeated here.
 */
class PerfConfigReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const FPC_BUILT_IN = 1;
    private const FPC_VARNISH = 2;
    private const MIN_FPC_TTL_SECONDS = 86400;
    private const CUSTOMER_GRID_INDEXER = 'customer_grid';
    private const REDIS_DEFAULT_PORT = '6379';
    private const SESSION_REDIS_HANDLERS = ['redis', 'valkey'];

    /**
     * @param State $state
     * @param ScopeConfigInterface $scopeConfig
     * @param DeploymentConfig $deploymentConfig
     * @param IndexerRegistry $indexerRegistry
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly State $state,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the performance config reporter.
     */
    public function getName(): string
    {
        return 'perf_config';
    }

    /**
     * Human-readable label for the performance config reporter block.
     */
    public function getLabel(): string
    {
        return 'Performance Config';
    }

    /**
     * One-line summary of what the performance config reporter covers.
     */
    public function getDescription(): string
    {
        return 'Deploy mode, full-page cache, asset merging/bundling and cache/session storage choices '
            . 'that affect storefront speed.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the performance-relevant configuration of this install.
     */
    public function getStatus(): array
    {
        $fpcApplication = (int)$this->scopeConfig->getValue(
            'system/full_page_cache/caching_application',
            AppScopeInterface::SCOPE_DEFAULT
        );
        $fpcTtl = (int)$this->scopeConfig->getValue('system/full_page_cache/ttl', AppScopeInterface::SCOPE_DEFAULT);
        $cacheBackend = (string)$this->deploymentConfig->get('cache/frontend/default/backend', '');
        $sessionHandler = (string)$this->deploymentConfig->get('session/save', 'files');

        $fields = [
            'deploy_mode' => $this->field->varchar(
                'Deploy Mode',
                $this->deployMode(),
                criticalValues: ['developer', 'default']
            ),
            'fpc_application' => $this->field->varchar(
                'Full Page Cache',
                match ($fpcApplication) {
                    self::FPC_VARNISH => 'varnish',
                    self::FPC_BUILT_IN => 'built-in',
                    default => 'unknown',
                }
            ),
            'fpc_is_built_in' => $this->field->bool(
                'Full Page Cache Is Built-in',
                $fpcApplication === self::FPC_BUILT_IN,
                criticalWhen: true
            ),
            'fpc_ttl_seconds' => $this->field->number(
                'Full Page Cache TTL',
                $fpcTtl,
                $fpcTtl > 0 && $fpcTtl < self::MIN_FPC_TTL_SECONDS ? Field::SEVERITY_WARNING : null,
                unit: Unit::SECONDS
            ),
            'cache_backend_is_file_based' => $this->field->bool(
                'Cache Backend Is File-based',
                !str_contains(strtolower($cacheBackend), 'redis') && !str_contains(strtolower($cacheBackend), 'memcache'),
                criticalWhen: true
            ),
            'session_is_file_based' => $this->field->bool(
                'Sessions Are File-based',
                $sessionHandler === 'files',
                criticalWhen: true
            ),
            'js_merge_enabled' => $this->flag('JS Merge Enabled', 'dev/js/merge_files'),
            'css_merge_enabled' => $this->flag('CSS Merge Enabled', 'dev/css/merge_css_files'),
            'js_bundling_enabled' => $this->flag('JS Bundling Enabled', 'dev/js/enable_js_bundling'),
            'html_minify_enabled' => $this->field->bool(
                'HTML Minify Enabled',
                $this->scopeConfig->isSetFlag('dev/template/minify_html', AppScopeInterface::SCOPE_DEFAULT),
                criticalWhen: false
            ),
            'css_minify_enabled' => $this->minifyFlag('CSS Minify Enabled', 'dev/css/minify_files'),
            'js_minify_enabled' => $this->minifyFlag('JS Minify Enabled', 'dev/js/minify_files'),
            'critical_css_enabled' => $this->field->bool(
                'Critical CSS Enabled',
                $this->scopeConfig->isSetFlag('dev/css/use_css_critical_path', AppScopeInterface::SCOPE_DEFAULT)
            ),
            'debug_logging_in_production' => $this->field->bool(
                'Debug Logging On In Production',
                $this->deployMode() === State::MODE_PRODUCTION && $this->isEnabledSetting(
                    $this->deploymentConfig->get('dev/debug/debug_logging')
                ),
                criticalWhen: true
            ),
            'db_profiler_enabled' => $this->field->bool(
                'Database Profiler Enabled',
                $this->dbProfilerEnabled(),
                criticalWhen: true
            ),
            'shared_redis_database' => $this->field->bool(
                'Cache And Sessions Share A Redis Database',
                $this->hasSharedRedisDatabase(),
                criticalWhen: true
            ),
            'l2_cache_enabled' => $this->field->bool(
                'L2 Cache Enabled',
                str_contains($cacheBackend, 'RemoteSynchronizedCache')
            ),
        ];

        $customerGridScheduled = $this->customerGridIsScheduled();
        if ($customerGridScheduled !== null) {
            $fields['customer_grid_not_update_on_save'] = $this->field->bool(
                'Customer Grid Not On "Update on Save"',
                $customerGridScheduled,
                criticalWhen: true
            );
        }

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), $fields + $this->sessionFields($sessionHandler))];
    }

    /**
     * Display-only session tuning facts, reported only when sessions live in Redis/Valkey. Locking
     * is a deliberate trade-off (safe vs. fewer lock waits), so none of these carry a color.
     *
     * @param string $sessionHandler
     * @return array<string, \StackNuts\StackGauge\Api\Field\FieldInterface>
     */
    private function sessionFields(string $sessionHandler): array
    {
        $fields = ['session_save_handler' => $this->field->varchar('Session Save Handler', $sessionHandler)];
        if (!in_array($sessionHandler, self::SESSION_REDIS_HANDLERS, true)) {
            return $fields;
        }

        $config = $this->deploymentConfig->get('session/' . $sessionHandler);
        $config = is_array($config) ? $config : [];
        $fields['session_locking_disabled'] = $this->field->bool(
            'Session Locking Disabled',
            $this->isEnabledSetting($config['disable_locking'] ?? null)
        );
        $fields['session_compression_lib'] = $this->field->varchar(
            'Session Compression',
            (string)($config['compression_library'] ?? $config['compression_lib'] ?? '') ?: 'none'
        );
        $fields['session_max_concurrency'] = $this->field->number(
            'Session Max Concurrency',
            (int)($config['max_concurrency'] ?? 6)
        );
        $fields['session_break_after_frontend'] = $this->field->number(
            'Session Break After (frontend)',
            (int)($config['break_after_frontend'] ?? 5),
            unit: Unit::SECONDS
        );

        return $fields;
    }

    /**
     * Whether any database connection in env.php has the query profiler switched on.
     */
    private function dbProfilerEnabled(): bool
    {
        $connections = $this->deploymentConfig->get('db/connection');
        foreach (is_array($connections) ? $connections : [] as $connection) {
            if (is_array($connection) && $this->isEnabledSetting($connection['profiler'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the default cache, page cache and Redis sessions do not all use their own host/port/database.
     * Only the comparison result leaves this method; hosts and ports are never reported.
     */
    private function hasSharedRedisDatabase(): bool
    {
        $endpoints = [];
        $frontends = $this->deploymentConfig->get('cache/frontend');
        foreach (['default', 'page_cache'] as $id) {
            $frontend = is_array($frontends) ? ($frontends[$id] ?? null) : null;
            $options = is_array($frontend) ? ($frontend['backend_options'] ?? []) : [];
            if (str_contains((string)($frontend['backend'] ?? ''), 'RemoteSynchronizedCache')) {
                $frontend['backend'] = $options['remote_backend'] ?? '';
                $options = $options['remote_backend_options'] ?? [];
            }
            if (is_array($options) && str_contains(strtolower((string)($frontend['backend'] ?? '')), 'redis')) {
                $endpoints[] = $this->endpointKey($options['server'] ?? '', $options['port'] ?? '', $options['database'] ?? 0);
            }
        }

        $sessionHandler = (string)$this->deploymentConfig->get('session/save');
        if (in_array($sessionHandler, self::SESSION_REDIS_HANDLERS, true)) {
            $session = $this->deploymentConfig->get('session/' . $sessionHandler);
            if (is_array($session)) {
                $endpoints[] = $this->endpointKey($session['host'] ?? '', $session['port'] ?? '', $session['database'] ?? 0);
            }
        }

        return count($endpoints) !== count(array_unique($endpoints));
    }

    /**
     * Normalized "host:port:database" comparison key.
     *
     * @param mixed $host
     * @param mixed $port
     * @param mixed $database
     */
    private function endpointKey(mixed $host, mixed $port, mixed $database): string
    {
        return sprintf(
            '%s:%s:%d',
            strtolower((string)$host),
            (string)$port !== '' ? (string)$port : self::REDIS_DEFAULT_PORT,
            (int)$database
        );
    }

    /**
     * Whether the customer grid indexer is on "Update by Schedule", or null if the indexer can't be loaded.
     * Magento's schedule mode never refreshes this grid, so schedule mode is the wrong setting.
     */
    private function customerGridIsScheduled(): ?bool
    {
        try {
            return $this->indexerRegistry->get(self::CUSTOMER_GRID_INDEXER)->isScheduled();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Truthiness of an env.php flag, where "0", "false", "off" and "" all mean disabled.
     *
     * @param mixed $value
     */
    private function isEnabledSetting(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
        }

        return !empty($value);
    }

    /**
     * Current deploy mode, or "unknown" if it cannot be resolved.
     */
    private function deployMode(): string
    {
        try {
            return $this->state->getMode();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Neutral bool for a default-scope flag; merge/bundling is workload-dependent so it carries no color.
     *
     * @param string $label
     * @param string $path
     */
    private function flag(string $label, string $path): \StackNuts\StackGauge\Api\Field\BoolField
    {
        return $this->field->bool($label, $this->scopeConfig->isSetFlag($path, AppScopeInterface::SCOPE_DEFAULT));
    }

    /**
     * Bool for a default-scope minify flag; unlike merge/bundling, off is always the wrong value in production.
     *
     * @param string $label
     * @param string $path
     */
    private function minifyFlag(string $label, string $path): \StackNuts\StackGauge\Api\Field\BoolField
    {
        return $this->field->bool(
            $label,
            $this->scopeConfig->isSetFlag($path, AppScopeInterface::SCOPE_DEFAULT),
            criticalWhen: false
        );
    }
}
