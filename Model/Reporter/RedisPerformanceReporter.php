<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use Credis_Client;
use Magento\Framework\App\DeploymentConfig;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\PerformanceSectionTrait;
use Throwable;

/**
 * Memory pressure, evictions and hit ratio for the Redis instances behind cache and session.
 * Reachability and version live in the core Redis reporter; this adds the performance side.
 * Backends are de-duplicated by host:port since INFO is instance-wide, so two cache databases
 * on one instance aren't double counted.
 */
class RedisPerformanceReporter implements ReporterInterface, DeclaresSectionInterface, MetricCatalogInterface, DeclaresSlotInterface
{
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const CONNECT_TIMEOUT_SECONDS = 2.0;
    private const METRIC_MEMORY_USED = 'redis_perf.memory_used_pct';
    private const METRIC_EVICTED_KEYS = 'redis_perf.evicted_keys';
    private const METRIC_HIT_RATE = 'redis_perf.hit_rate';

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Field $field,
        private readonly Section $section
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
        $maxMemoryPct = null;
        $minHitRate = null;

        foreach ($this->instances() as $name => $options) {
            $info = $this->info($options);
            if ($info === null) {
                continue;
            }

            $used = (int)($info['used_memory'] ?? 0);
            $max = (int)($info['maxmemory'] ?? 0);
            $hits = (int)($info['keyspace_hits'] ?? 0);
            $misses = (int)($info['keyspace_misses'] ?? 0);
            $memoryPct = $max > 0 ? round(($used / $max) * 100, 2) : null;
            $hitRate = ($hits + $misses) > 0 ? round(($hits / ($hits + $misses)) * 100, 2) : null;

            $evicted += (int)($info['evicted_keys'] ?? 0);
            if ($memoryPct !== null) {
                $maxMemoryPct = max($maxMemoryPct ?? 0.0, $memoryPct);
            }
            if ($hitRate !== null) {
                $minHitRate = min($minHitRate ?? 100.0, $hitRate);
            }

            $rows[] = $this->field->array($name, [
                'name' => $this->field->varchar('Name', $name),
                'used_memory_mb' => $this->field->number('Used Memory', (int)round($used / 1048576), unit: Unit::MEGABYTES),
                'maxmemory_mb' => $this->field->number('Max Memory', (int)round($max / 1048576), unit: Unit::MEGABYTES),
                'memory_used_pct' => $this->field->number('Memory Used', $memoryPct ?? 0.0, unit: Unit::PERCENT),
                'maxmemory_policy' => $this->field->varchar('Eviction Policy', (string)($info['maxmemory_policy'] ?? '')),
                'evicted_keys' => $this->field->number('Evicted Keys', (int)($info['evicted_keys'] ?? 0)),
                'hit_rate' => $this->field->number('Hit Rate', $hitRate ?? 0.0, unit: Unit::PERCENT),
                'connected_clients' => $this->field->number('Connected Clients', (int)($info['connected_clients'] ?? 0)),
            ]);
        }

        $summary = ['instances_checked' => $this->field->number('Instances Checked', count($rows))];
        $summary['evicted_keys'] = $this->field->trackableNumber(
            'Evicted Keys (since start)',
            $evicted,
            self::METRIC_EVICTED_KEYS,
            MetricDefinition::AGGREGATION_DELTA
        );
        if ($maxMemoryPct !== null) {
            $summary['memory_used_pct'] = $this->field->trackableNumber(
                'Peak Memory Used',
                $maxMemoryPct,
                self::METRIC_MEMORY_USED,
                MetricDefinition::AGGREGATION_MAX,
                unit: Unit::PERCENT
            );
        }
        if ($minHitRate !== null) {
            $summary['hit_rate'] = $this->field->trackableNumber(
                'Lowest Hit Rate',
                $minHitRate,
                self::METRIC_HIT_RATE,
                MetricDefinition::AGGREGATION_MIN,
                unit: Unit::PERCENT
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
                description: 'A Redis instance has a hit rate of {value}.',
                impact: 'Most cache lookups are missing, so Magento is regenerating content on nearly every request.',
                criticalThreshold: 50,
                unit: Unit::PERCENT
            ),
        ];
    }

    /**
     * Distinct Redis instances used by cache frontends and sessions, keyed by a readable name.
     *
     * @return array<string, array<string,mixed>>
     */
    private function instances(): array
    {
        $found = [];
        $add = function (string $name, array $options) use (&$found): void {
            $host = (string)($options['server'] ?? $options['host'] ?? '');
            if ($host === '') {
                return;
            }
            $key = $host . ':' . (int)($options['port'] ?? 6379);
            $found[$key]['name'] = ($found[$key]['name'] ?? '') === '' ? $name : $found[$key]['name'] . '+' . $name;
            $found[$key]['options'] = $options;
        };

        foreach ((array)$this->deploymentConfig->get('cache/frontend', []) as $id => $frontend) {
            if (($frontend['backend'] ?? null) === 'redis') {
                $add('cache_' . $id, (array)($frontend['backend_options'] ?? []));
            }
        }

        $session = (array)$this->deploymentConfig->get('session', []);
        if (($session['save'] ?? null) === 'redis') {
            $add('session', (array)($session['redis'] ?? []));
        }

        $named = [];
        foreach ($found as $entry) {
            $named[$entry['name']] = $entry['options'];
        }

        return $named;
    }

    /**
     * INFO for one instance, or null when it can't be reached.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null
     */
    private function info(array $options): ?array
    {
        try {
            $client = new Credis_Client(
                (string)($options['server'] ?? $options['host']),
                (int)($options['port'] ?? 6379),
                self::CONNECT_TIMEOUT_SECONDS,
                '',
                (int)($options['database'] ?? 0),
                ($options['password'] ?? null) ?: null
            );
            $client->setMaxConnectRetries(0);

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
}
