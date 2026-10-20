<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use Credis_Client;
use Magento\Framework\FlagManager;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Redis\RedisInstance;
use StackNuts\StackGauge\Model\Redis\RedisInstanceResolver;
use StackNuts\StackGauge\Model\Reporter\Concern\PerformanceSectionTrait;
use Throwable;

/**
 * Memory pressure, evictions and hit ratio for the Redis instances behind cache and session.
 * Reachability and version live in the core Redis reporter; this adds the performance side.
 * Instances come from the core RedisInstanceResolver and are de-duplicated by host:port since INFO is
 * instance-wide, so two cache databases on one server aren't double counted.
 *
 * Redis's hit/miss counters run from server start, so their ratio on a long-lived, mostly idle instance says
 * nothing about today. The alertable hit rate is therefore taken from the change in those counters since the
 * previous report (kept in a flag), and only once enough lookups happened in between to mean something.
 */
class RedisPerformanceReporter implements ReporterInterface, DeclaresSectionInterface, DeclaresSubjectInterface, MetricCatalogInterface, DeclaresSlotInterface
{
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const CONNECT_TIMEOUT_SECONDS = 2.0;
    private const READ_TIMEOUT_SECONDS = 3.0;
    private const METRIC_MEMORY_USED = 'redis_perf.memory_used_pct';
    private const METRIC_EVICTED_KEYS = 'redis_perf.evicted_keys';
    private const METRIC_HIT_RATE = 'redis_perf.hit_rate';
    private const COUNTERS_FLAG = 'stacknuts_stackgauge_redis_perf_counters';
    private const MIN_RECENT_LOOKUPS = 1000;

    /**
     * @param RedisInstanceResolver $resolver
     * @param Field $field
     * @param Section $section
     * @param FlagManager $flagManager
     */
    public function __construct(
        private readonly RedisInstanceResolver $resolver,
        private readonly Field $field,
        private readonly Section $section,
        private readonly FlagManager $flagManager
    ) {
    }

    /**
     * Payload key for the Redis performance reporter.
     */
    public function getName(): string
    {
        return 'redis_perf';
    }

    /**
     * Human-readable label for the Redis performance reporter block.
     */
    public function getLabel(): string
    {
        return 'Redis Performance';
    }

    /**
     * One-line summary of what the Redis performance reporter covers.
     */
    public function getDescription(): string
    {
        return 'Memory usage, evictions and hit ratio for the Redis instances behind cache and sessions.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports per-instance Redis performance plus worst-case aggregates across instances.
     */
    public function getStatus(): array
    {
        $rows = [];
        $evicted = 0;
        $unreachable = 0;
        $maxMemoryPct = null;
        $minHitRate = null;
        $previousCounters = $this->previousCounters();
        $counters = $previousCounters;

        foreach ($this->servers() as $server) {
            $name = $server['name'];
            $info = $this->info($server['instance']);
            if ($info === null) {
                $unreachable++;
                continue;
            }

            $used = (int)($info['used_memory'] ?? 0);
            $max = (int)($info['maxmemory'] ?? 0);
            $hits = (int)($info['keyspace_hits'] ?? 0);
            $misses = (int)($info['keyspace_misses'] ?? 0);
            $memoryPct = $max > 0 ? round(($used / $max) * 100, 2) : null;
            $lifetimeHitRate = ($hits + $misses) > 0 ? round(($hits / ($hits + $misses)) * 100, 2) : null;

            $serverKey = $server['instance']->serverKey();
            [$recentHits, $recentMisses] = $this->recentCounts($previousCounters[$serverKey] ?? null, $hits, $misses);
            $counters[$serverKey] = ['hits' => $hits, 'misses' => $misses];
            $recentLookups = $recentHits + $recentMisses;
            $hitRate = $recentLookups >= self::MIN_RECENT_LOOKUPS
                ? round(($recentHits / $recentLookups) * 100, 2)
                : null;

            $evicted += (int)($info['evicted_keys'] ?? 0);
            if ($memoryPct !== null) {
                $maxMemoryPct = max($maxMemoryPct ?? 0.0, $memoryPct);
            }
            // A session-only instance is write-heavy and its hit ratio says nothing about cache health.
            if ($hitRate !== null && $server['serves_cache']) {
                $minHitRate = min($minHitRate ?? 100.0, $hitRate);
            }

            $rows[] = $this->field->array($name, [
                'name' => $this->field->varchar('Name', $name),
                'used_memory_mb' => $this->field->number('Used Memory', (int)round($used / 1048576), unit: Unit::MEGABYTES),
                'maxmemory_mb' => $this->field->number('Max Memory', (int)round($max / 1048576), unit: Unit::MEGABYTES),
                'memory_used_pct' => $this->field->number('Memory Used', $memoryPct ?? 0.0, unit: Unit::PERCENT),
                'maxmemory_policy' => $this->field->varchar('Eviction Policy', (string)($info['maxmemory_policy'] ?? '')),
                'evicted_keys' => $this->field->number('Evicted Keys', (int)($info['evicted_keys'] ?? 0)),
                'hit_rate' => $this->field->number('Hit Rate (since start)', $lifetimeHitRate ?? 0.0, unit: Unit::PERCENT),
                'recent_lookups' => $this->field->number('Lookups Since Last Report', $recentLookups),
                'recent_hit_rate' => $this->field->varchar(
                    'Hit Rate Since Last Report',
                    $hitRate === null ? 'Too few lookups' : $hitRate . '%'
                ),
                'connected_clients' => $this->field->number('Connected Clients', (int)($info['connected_clients'] ?? 0)),
            ]);
        }

        $this->flagManager->saveFlag(self::COUNTERS_FLAG, $counters);

        $summary = ['instances_checked' => $this->field->number('Instances Checked', count($rows))];
        if ($unreachable > 0) {
            $summary['instances_unreachable'] = $this->field->number('Instances Unreachable', $unreachable);
        }
        // The summed counter would drop and then jump back when an instance misses a run, which the
        // delta aggregation reads as a burst of evictions - so only record it from a complete run.
        $summary['evicted_keys'] = $unreachable === 0
            ? $this->field->trackableNumber(
                'Evicted Keys (since start)',
                $evicted,
                self::METRIC_EVICTED_KEYS,
                MetricDefinition::AGGREGATION_DELTA
            )
            : $this->field->number('Evicted Keys (since start)', $evicted);
        if ($maxMemoryPct !== null) {
            $summary['memory_used_pct'] = $this->field->trackableNumber(
                'Peak Memory Used',
                $maxMemoryPct,
                self::METRIC_MEMORY_USED,
                MetricDefinition::AGGREGATION_MAX
            );
        }
        if ($minHitRate !== null) {
            $summary['hit_rate'] = $this->field->trackableNumber(
                'Lowest Hit Rate (since last report)',
                $minHitRate,
                self::METRIC_HIT_RATE,
                MetricDefinition::AGGREGATION_MIN
            );
        }

        return [
            'summary' => $this->section->facts('summary', 'Summary', $this->getDescription(), $summary),
            'instances' => $this->section->table('instances', 'Instances', $this->getDescription(), $rows),
        ];
    }

    /**
     * Alertable metrics: memory pressure, evictions and hit ratio.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_MEMORY_USED,
                'Redis: Peak Memory Used',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                90,
                360,
                null,
                description: 'A Redis instance is {value} of its memory limit.',
                impact: 'Near the limit Redis evicts cache entries (or refuses writes), hurting hit rate and speed.',
                criticalThreshold: 98,
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_EVICTED_KEYS,
                'Redis: Evicted Keys',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                0,
                360,
                null,
                description: 'Redis evicted {value} key(s) to stay under its memory limit.',
                impact: 'Evicted cache entries must be rebuilt, and evicted session keys log customers out.'
            ),
            new MetricDefinition(
                self::METRIC_HIT_RATE,
                'Redis: Lowest Hit Rate',
                MetricDefinition::AGGREGATION_MIN,
                MetricDefinition::OPERATOR_LT,
                80,
                360,
                null,
                description: 'A Redis instance answered only {value} of its lookups since the previous report.',
                impact: 'Most cache lookups are missing, so Magento is regenerating content on nearly every request.',
                criticalThreshold: 50,
                unit: Unit::PERCENT
            ),
        ];
    }

    /**
     * Hits and misses since the previous report. A counter that went backwards means the server restarted in
     * between, so everything it has counted since is recent. With no previous report there is nothing to
     * compare against, so nothing counts as recent yet.
     *
     * @param array{hits?: int, misses?: int}|null $previous
     * @param int $hits
     * @param int $misses
     * @return array{0: int, 1: int}
     */
    private function recentCounts(?array $previous, int $hits, int $misses): array
    {
        if ($previous === null) {
            return [0, 0];
        }

        $previousHits = (int)($previous['hits'] ?? 0);
        $previousMisses = (int)($previous['misses'] ?? 0);
        if ($hits < $previousHits || $misses < $previousMisses) {
            return [$hits, $misses];
        }

        return [$hits - $previousHits, $misses - $previousMisses];
    }

    /**
     * Counters saved by the previous report, by host:port.
     *
     * @return array<string, array{hits: int, misses: int}>
     */
    private function previousCounters(): array
    {
        $saved = $this->flagManager->getFlagData(self::COUNTERS_FLAG);

        return is_array($saved) ? $saved : [];
    }

    /**
     * Distinct Redis servers (host:port) behind cache and sessions. INFO is server-wide, so two databases
     * on one server are checked once and their roles joined into one readable name.
     *
     * @return list<array{name: string, instance: RedisInstance, serves_cache: bool}>
     */
    private function servers(): array
    {
        $servers = [];
        foreach ($this->resolver->resolve() as $instance) {
            $key = $instance->serverKey();
            $servers[$key] ??= ['names' => [], 'instance' => $instance, 'serves_cache' => false];
            $servers[$key]['names'] = array_merge($servers[$key]['names'], $instance->roles);
            foreach ($instance->roles as $role) {
                if ($role !== 'session') {
                    $servers[$key]['serves_cache'] = true;
                }
            }
        }

        return array_values(array_map(static fn (array $s): array => [
            'name' => implode('+', array_unique($s['names'])),
            'instance' => $s['instance'],
            'serves_cache' => $s['serves_cache'],
        ], $servers));
    }

    /**
     * INFO for one instance, or null when it can't be reached.
     *
     * @param RedisInstance $instance
     * @return array<string,mixed>|null
     */
    protected function info(RedisInstance $instance): ?array
    {
        try {
            $client = new Credis_Client(
                $instance->host,
                $instance->port,
                min($instance->timeout, self::CONNECT_TIMEOUT_SECONDS),
                '',
                $instance->database,
                $instance->password,
                $instance->username
            );
            $client->setMaxConnectRetries(0);
            $client->setReadTimeout(self::READ_TIMEOUT_SECONDS);

            return $client->info();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run 1 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 1;
    }

    /**
     * Subject shared with the other reporters describing the same thing, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'redis';
    }
}
