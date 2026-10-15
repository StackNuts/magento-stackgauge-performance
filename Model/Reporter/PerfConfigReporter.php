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

    /**
     * @param State $state
     * @param ScopeConfigInterface $scopeConfig
     * @param DeploymentConfig $deploymentConfig
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly State $state,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeploymentConfig $deploymentConfig,
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

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
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
            'critical_css_enabled' => $this->field->bool(
                'Critical CSS Enabled',
                $this->scopeConfig->isSetFlag('dev/css/use_css_critical_path', AppScopeInterface::SCOPE_DEFAULT)
            ),
        ])];
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
}
