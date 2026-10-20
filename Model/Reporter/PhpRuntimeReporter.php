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

    /**
     * A snapshot older than this no longer describes the running web process, so it yields no metrics.
     */
    private const STALE_AFTER_SECONDS = 7200;

    /**
     * Time OPcache needs after a start/restart before its hit rate is representative.
     */
    private const WARMUP_SECONDS = 900;
    /**
     * OPcache's hit rate is cumulative since its last restart, so on a quiet store the misses from compiling every
     * script once dominate it for a long time. A couple of hundred page views' worth of file accesses (Magento touches
     * roughly a thousand files per request) before the rate is trusted keeps an idle staging box from reporting a
     * permanently "low" hit rate.
     */
    private const WARMUP_MIN_ACCESSES = 200000;
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
     * Reports the web nodes' runtime snapshots, aggregated worst-case across the nodes whose snapshot is still
     * fresh, plus one row per node. Metrics are withheld when every snapshot is stale, and the OPcache hit
     * rate is withheld while the cache is still warming up after a start or restart.
     */
    public function getStatus(): array
    {
        $snapshots = $this->store->readAll();
        if ($snapshots === []) {
            return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
                'snapshot_available' => $this->field->bool('Snapshot Available', false),
            ])];
        }

        $now = time();
        $fresh = array_filter(
            $snapshots,
            static fn (array $snapshot): bool => $now - (int)($snapshot['collected_at'] ?? 0) <= self::STALE_AFTER_SECONDS
        );
        $newest = $this->newest($fresh !== [] ? $fresh : $snapshots);

        $fields = [
            'snapshot_available' => $this->field->bool('Snapshot Available', true),
            'snapshot_age_minutes' => $this->field->number(
                'Snapshot Age',
                max(0, (int)floor(($now - (int)($newest['collected_at'] ?? $now)) / 60)),
                unit: Unit::MINUTES
            ),
            'snapshot_stale' => $this->field->bool('Snapshot Stale', $fresh === [], criticalWhen: true),
            'php_version' => $this->field->varchar('PHP Version', (string)($newest['php_version'] ?? '')),
            'sapi' => $this->field->varchar('SAPI', (string)($newest['sapi'] ?? '')),
            'memory_limit' => $this->field->varchar('Memory Limit', (string)($newest['memory_limit'] ?? '')),
        ];

        if ($fresh !== []) {
            $fields += $this->metricFields($fresh);
        }

        return [
            'general' => $this->section->facts('general', 'General', $this->getDescription(), $fields),
            'hosts' => $this->section->table('hosts', 'Web Nodes', $this->getDescription(), $this->hostRows($snapshots, $now)),
        ];
    }

    /**
     * The aggregated, alertable fields across fresh node snapshots.
     *
     * @param array<string, array<string, mixed>> $fresh
     * @return array<string, \StackNuts\StackGauge\Api\Field\FieldInterface>
     */
    private function metricFields(array $fresh): array
    {
        $realpathPct = 0.0;
        $opcaches = [];
        $opcacheEverywhere = true;
        foreach ($fresh as $snapshot) {
            $realpath = (array)($snapshot['realpath_cache'] ?? []);
            $limit = (int)($realpath['limit_bytes'] ?? 0);
            $realpathPct = max($realpathPct, $limit > 0 ? round(((int)($realpath['used_bytes'] ?? 0) / $limit) * 100, 2) : 0.0);
            if (is_array($snapshot['opcache'] ?? null)) {
                $opcaches[] = ['opcache' => $snapshot['opcache'], 'warm' => $this->isWarm($snapshot)];
            } else {
                $opcacheEverywhere = false;
            }
        }

        $fields = [
            'opcache_enabled' => $this->field->bool('OPcache Enabled', $opcacheEverywhere, criticalWhen: false),
            'realpath_cache_used_pct' => $this->field->trackableNumber(
                'Realpath Cache Used',
                $realpathPct,
                self::METRIC_REALPATH_CACHE_USED,
                MetricDefinition::AGGREGATION_MAX
            ),
        ];
        if ($opcaches === []) {
            return $fields;
        }

        $column = static fn (string $key): array => array_map(
            static fn (array $entry): float => (float)($entry['opcache'][$key] ?? 0),
            $opcaches
        );
        $warmRates = array_map(
            static fn (array $entry): float => (float)($entry['opcache']['hit_rate'] ?? 0),
            array_filter($opcaches, static fn (array $entry): bool => $entry['warm'])
        );
        $any = static fn (string $key): bool => in_array(true, array_map(
            static fn (array $entry): bool => (bool)($entry['opcache'][$key] ?? false),
            $opcaches
        ), true);

        if ($warmRates !== []) {
            $fields['opcache_hit_rate'] = $this->field->trackableNumber(
                'OPcache Hit Rate',
                min($warmRates),
                self::METRIC_HIT_RATE,
                MetricDefinition::AGGREGATION_MIN
            );
        }

        return $fields + [
            'opcache_warming_up' => $this->field->bool('OPcache Warming Up', count($warmRates) < count($opcaches)),
            'opcache_memory_used_pct' => $this->field->trackableNumber(
                'OPcache Memory Used',
                max($column('memory_used_pct')),
                self::METRIC_MEMORY_USED,
                MetricDefinition::AGGREGATION_MAX
            ),
            'opcache_wasted_pct' => $this->field->trackableNumber(
                'OPcache Wasted',
                max($column('wasted_pct')),
                self::METRIC_WASTED,
                MetricDefinition::AGGREGATION_MAX
            ),
            'opcache_keys_used_pct' => $this->field->trackableNumber(
                'OPcache Keys Used',
                max($column('keys_used_pct')),
                self::METRIC_KEYS_USED,
                MetricDefinition::AGGREGATION_MAX
            ),
            'opcache_interned_strings_used_pct' => $this->field->trackableNumber(
                'OPcache Interned Strings Used',
                max($column('interned_strings_used_pct')),
                self::METRIC_INTERNED_STRINGS_USED,
                MetricDefinition::AGGREGATION_MAX
            ),
            'opcache_cached_scripts' => $this->field->number('OPcache Cached Scripts', (int)max($column('cached_scripts'))),
            'opcache_oom_restarts' => $this->field->trackableNumber(
                'OPcache Out-of-Memory Restarts',
                (int)array_sum($column('oom_restarts')),
                self::METRIC_OOM_RESTARTS,
                MetricDefinition::AGGREGATION_DELTA
            ),
            'opcache_cache_full' => $this->field->bool('OPcache Full', $any('cache_full'), criticalWhen: true),
            'opcache_validate_timestamps' => $this->field->bool(
                'OPcache Validates Timestamps',
                $any('validate_timestamps'),
                criticalWhen: true
            ),
            'jit_enabled' => $this->field->bool('JIT Enabled', $any('jit_enabled')),
        ];
    }

    /**
     * One display-only row per node, stale ones included so a node that stopped reporting is visible.
     *
     * @param array<string, array<string, mixed>> $snapshots
     * @param int $now
     * @return list<\StackNuts\StackGauge\Api\Field\ArrayField>
     */
    private function hostRows(array $snapshots, int $now): array
    {
        $rows = [];
        foreach ($snapshots as $host => $snapshot) {
            $age = $now - (int)($snapshot['collected_at'] ?? $now);
            $opcache = is_array($snapshot['opcache'] ?? null) ? $snapshot['opcache'] : null;
            $row = [
                'host' => $this->field->varchar('Host', (string)$host),
                'snapshot_age_minutes' => $this->field->number('Snapshot Age', max(0, (int)floor($age / 60)), unit: Unit::MINUTES),
                'stale' => $this->field->bool('Stale', $age > self::STALE_AFTER_SECONDS, criticalWhen: true),
                'php_version' => $this->field->varchar('PHP Version', (string)($snapshot['php_version'] ?? '')),
                'opcache_enabled' => $this->field->bool('OPcache Enabled', $opcache !== null),
            ];
            if ($opcache !== null) {
                $row['opcache_hit_rate'] = $this->field->number('OPcache Hit Rate', (float)$opcache['hit_rate'], unit: Unit::PERCENT);
                $row['opcache_warming_up'] = $this->field->bool('OPcache Warming Up', !$this->isWarm($snapshot));
                $row['opcache_memory_used_pct'] = $this->field->number('OPcache Memory Used', (float)$opcache['memory_used_pct'], unit: Unit::PERCENT);
                $row['opcache_oom_restarts'] = $this->field->number('OPcache Out-of-Memory Restarts', (int)$opcache['oom_restarts']);
            }
            $rows[] = $this->field->array((string)$host, $row);
        }

        return $rows;
    }

    /**
     * Whether the snapshot's OPcache had been running long enough, and served enough accesses, when it was captured,
     * for its hit rate to mean something. A snapshot without start information (written by an older version) is
     * trusted.
     *
     * @param array<string, mixed> $snapshot
     */
    private function isWarm(array $snapshot): bool
    {
        $opcache = (array)($snapshot['opcache'] ?? []);
        if (!array_key_exists('warm_since', $opcache)) {
            return true;
        }

        $enoughAccesses = !array_key_exists('accesses', $opcache)
            || (int)$opcache['accesses'] >= self::WARMUP_MIN_ACCESSES;
        $since = (int)$opcache['warm_since'];
        if ($since > 0) {
            return $enoughAccesses && (int)($snapshot['collected_at'] ?? 0) - $since >= self::WARMUP_SECONDS;
        }

        return array_key_exists('accesses', $opcache) && $enoughAccesses;
    }

    /**
     * The most recently collected of the given snapshots.
     *
     * @param array<string, array<string, mixed>> $snapshots
     * @return array<string, mixed>
     */
    private function newest(array $snapshots): array
    {
        $newest = [];
        foreach ($snapshots as $snapshot) {
            if ($newest === [] || (int)($snapshot['collected_at'] ?? 0) > (int)($newest['collected_at'] ?? 0)) {
                $newest = $snapshot;
            }
        }

        return $newest;
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
