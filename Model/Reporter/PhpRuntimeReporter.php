<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\PerformanceSectionTrait;
use StackNuts\StackGaugePerformance\Model\Runtime\WebRuntimeStore;

/**
 * OPcache and PHP runtime health as seen by the FPM/web SAPI, read from the snapshot a sampled
 * storefront request writes (see Observer\CaptureWebRuntime) - cron runs under the CLI, which
 * would report a different, irrelevant OPcache.
 */
class PhpRuntimeReporter implements ReporterInterface, DeclaresSectionInterface, DeclaresSubjectInterface, MetricCatalogInterface
{
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_HIT_RATE = 'php_runtime.opcache_hit_rate';
    private const METRIC_MEMORY_USED = 'php_runtime.opcache_memory_used_pct';
    private const METRIC_OOM_RESTARTS = 'php_runtime.opcache_oom_restarts';
    private const METRIC_WASTED = 'php_runtime.opcache_wasted_pct';
    private const METRIC_KEYS_USED = 'php_runtime.opcache_keys_used_pct';
    private const METRIC_INTERNED_STRINGS_USED = 'php_runtime.opcache_interned_strings_used_pct';
    private const METRIC_REALPATH_CACHE_USED = 'php_runtime.realpath_cache_used_pct';

    /**
     * @param WebRuntimeStore $store
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly WebRuntimeStore $store,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the PHP runtime reporter.
     */
    public function getName(): string
    {
        return 'php_runtime';
    }

    /**
     * Human-readable label for the PHP runtime reporter block.
     */
    public function getLabel(): string
    {
        return 'PHP Runtime';
    }

    /**
     * One-line summary of what the PHP runtime reporter covers.
     */
    public function getDescription(): string
    {
        return 'OPcache hit rate, memory pressure and restarts, plus the realpath cache, '
            . 'sampled from the web (FPM) process.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports the latest web-process runtime snapshot, or just that none exists yet.
     */
    public function getStatus(): array
    {
        $snapshot = $this->store->read();
        if ($snapshot === null) {
            return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
                'snapshot_available' => $this->field->bool('Snapshot Available', false),
            ])];
        }

        $opcache = $snapshot['opcache'] ?? null;
        $realpath = (array)($snapshot['realpath_cache'] ?? []);
        $realpathLimit = (int)($realpath['limit_bytes'] ?? 0);
        $realpathPct = $realpathLimit > 0 ? round(((int)($realpath['used_bytes'] ?? 0) / $realpathLimit) * 100, 2) : 0.0;

        $fields = [
            'snapshot_available' => $this->field->bool('Snapshot Available', true),
            'snapshot_age_minutes' => $this->field->number(
                'Snapshot Age',
                max(0, (int)floor((time() - (int)($snapshot['collected_at'] ?? time())) / 60)),
                unit: Unit::MINUTES
            ),
            'php_version' => $this->field->varchar('PHP Version', (string)($snapshot['php_version'] ?? '')),
            'sapi' => $this->field->varchar('SAPI', (string)($snapshot['sapi'] ?? '')),
            'memory_limit' => $this->field->varchar('Memory Limit', (string)($snapshot['memory_limit'] ?? '')),
            'opcache_enabled' => $this->field->bool('OPcache Enabled', is_array($opcache), criticalWhen: false),
            'realpath_cache_used_pct' => $this->field->trackableNumber(
                'Realpath Cache Used',
                $realpathPct,
                self::METRIC_REALPATH_CACHE_USED,
                MetricDefinition::AGGREGATION_MAX,
                unit: Unit::PERCENT
            ),
        ];

        if (is_array($opcache)) {
            $fields += [
                'opcache_hit_rate' => $this->field->trackableNumber(
                    'OPcache Hit Rate',
                    (float)$opcache['hit_rate'],
                    self::METRIC_HIT_RATE,
                    MetricDefinition::AGGREGATION_MIN,
                    unit: Unit::PERCENT
                ),
                'opcache_memory_used_pct' => $this->field->trackableNumber(
                    'OPcache Memory Used',
                    (float)$opcache['memory_used_pct'],
                    self::METRIC_MEMORY_USED,
                    MetricDefinition::AGGREGATION_MAX,
                    unit: Unit::PERCENT
                ),
                'opcache_wasted_pct' => $this->field->trackableNumber(
                    'OPcache Wasted',
                    (float)$opcache['wasted_pct'],
                    self::METRIC_WASTED,
                    MetricDefinition::AGGREGATION_MAX,
                    unit: Unit::PERCENT
                ),
                'opcache_keys_used_pct' => $this->field->trackableNumber(
                    'OPcache Keys Used',
                    (float)$opcache['keys_used_pct'],
                    self::METRIC_KEYS_USED,
                    MetricDefinition::AGGREGATION_MAX,
                    unit: Unit::PERCENT
                ),
                'opcache_interned_strings_used_pct' => $this->field->trackableNumber(
                    'OPcache Interned Strings Used',
                    (float)$opcache['interned_strings_used_pct'],
                    self::METRIC_INTERNED_STRINGS_USED,
                    MetricDefinition::AGGREGATION_MAX,
                    unit: Unit::PERCENT
                ),
                'opcache_cached_scripts' => $this->field->number(
                    'OPcache Cached Scripts',
                    (int)$opcache['cached_scripts']
                ),
                'opcache_oom_restarts' => $this->field->trackableNumber(
                    'OPcache Out-of-Memory Restarts',
                    (int)$opcache['oom_restarts'],
                    self::METRIC_OOM_RESTARTS,
                    MetricDefinition::AGGREGATION_DELTA
                ),
                'opcache_cache_full' => $this->field->bool(
                    'OPcache Full',
                    (bool)$opcache['cache_full'],
                    criticalWhen: true
                ),
                'opcache_validate_timestamps' => $this->field->bool(
                    'OPcache Validates Timestamps',
                    (bool)$opcache['validate_timestamps'],
                    criticalWhen: true
                ),
                'jit_enabled' => $this->field->bool('JIT Enabled', (bool)$opcache['jit_enabled']),
            ];
        }

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), $fields)];
    }

    /**
     * Alertable metrics: low hit rate, near-full OPcache memory, out-of-memory restarts, plus
     * three more OPcache/realpath-cache pressure percentages (added 2026-10) whose thresholds
     * mirror METRIC_MEMORY_USED's existing "90% full" style - a percentage-of-a-configured-
     * limit metric generalizes across server sizes the same way memory_used_pct already does.
     * Deliberately did not promote opcache_cached_scripts the same way: it's an absolute
     * script count, meaningless without knowing the codebase's actual file count, so no
     * universal threshold exists for it the way it does for these percentage metrics.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_HIT_RATE,
                'PHP Runtime: OPcache Hit Rate',
                MetricDefinition::AGGREGATION_MIN,
                MetricDefinition::OPERATOR_LT,
                95,
                360,
                null,
                description: 'OPcache hit rate fell to {value}.',
                impact: 'PHP is recompiling scripts on many requests, which slows every page.',
                criticalThreshold: 80,
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_MEMORY_USED,
                'PHP Runtime: OPcache Memory Used',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                90,
                360,
                null,
                description: 'OPcache memory is {value} full.',
                impact: 'Once full, OPcache restarts or stops caching scripts and response times degrade sharply.',
                criticalThreshold: 98,
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_OOM_RESTARTS,
                'PHP Runtime: OPcache Out-of-Memory Restarts',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                0,
                360,
                null,
                description: 'OPcache restarted {value} time(s) because it ran out of memory.',
                impact: 'Each restart empties the compiled-script cache, causing a burst of slow requests.'
            ),
            new MetricDefinition(
                self::METRIC_WASTED,
                'PHP Runtime: OPcache Wasted',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                20,
                360,
                null,
                description: 'OPcache wasted memory reached {value}.',
                impact: 'Wasted memory only clears on a restart - high waste means OPcache is close '
                    . 'to needing one, with the same slow-request burst an out-of-memory restart causes.',
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_KEYS_USED,
                'PHP Runtime: OPcache Keys Used',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                90,
                360,
                null,
                description: 'OPcache key slots are {value} full.',
                impact: 'Once full, OPcache stops caching new scripts (max_accelerated_files reached) '
                    . 'and falls back to recompiling them on every request.',
                criticalThreshold: 98,
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_INTERNED_STRINGS_USED,
                'PHP Runtime: OPcache Interned Strings Used',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                90,
                360,
                null,
                description: 'OPcache interned-strings buffer is {value} full.',
                impact: 'Once full, new strings can no longer be interned, increasing memory use '
                    . 'and GC pressure on every request.',
                criticalThreshold: 98,
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_REALPATH_CACHE_USED,
                'PHP Runtime: Realpath Cache Used',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                90,
                360,
                null,
                description: 'The realpath cache is {value} full.',
                impact: 'Once full, PHP falls back to real filesystem lookups for paths that no '
                    . 'longer fit, slowing file includes on every request.',
                unit: Unit::PERCENT
            ),
        ];
    }

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'php';
    }
}
