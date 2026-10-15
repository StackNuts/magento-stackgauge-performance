<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
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
 * MySQL/MariaDB runtime pressure from SHOW GLOBAL STATUS/VARIABLES: connection headroom, slow
 * queries, on-disk temp tables and InnoDB buffer pool hit rate. Counters are cumulative since
 * server start, so rates here are lifetime ratios and slow queries and deadlocks use a delta metric.
 */
class MysqlRuntimeReporter implements ReporterInterface, DeclaresSectionInterface, DeclaresSubjectInterface, MetricCatalogInterface, DeclaresSlotInterface
{
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_CONNECTION_USAGE = 'mysql_runtime.connection_usage_pct';
    private const METRIC_SLOW_QUERIES = 'mysql_runtime.slow_queries';
    private const METRIC_BUFFER_POOL_HIT = 'mysql_runtime.buffer_pool_hit_pct';
    private const METRIC_DEADLOCKS = 'mysql_runtime.innodb_deadlocks';

    private const STATUS_KEYS = [
        'Threads_connected', 'Threads_running', 'Max_used_connections', 'Slow_queries', 'Aborted_connects',
        'Created_tmp_tables', 'Created_tmp_disk_tables', 'Innodb_buffer_pool_reads',
        'Innodb_buffer_pool_read_requests', 'Uptime', 'Innodb_deadlocks',
    ];

    private const VARIABLE_KEYS = ['max_connections', 'innodb_buffer_pool_size', 'slow_query_log'];

    /**
     * @param ResourceConnection $resource
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the MySQL runtime reporter.
     */
    public function getName(): string
    {
        return 'mysql_runtime';
    }

    /**
     * Human-readable label for the MySQL runtime reporter block.
     */
    public function getLabel(): string
    {
        return 'MySQL Runtime';
    }

    /**
     * One-line summary of what the MySQL runtime reporter covers.
     */
    public function getDescription(): string
    {
        return 'Connection headroom, slow queries, temp tables spilling to disk and InnoDB buffer pool hit rate.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports database runtime pressure, or just that status was unreadable.
     */
    public function getStatus(): array
    {
        try {
            $connection = $this->resource->getConnection();
            $status = $connection->fetchPairs($this->showQuery('STATUS', self::STATUS_KEYS));
            $variables = $connection->fetchPairs($this->showQuery('VARIABLES', self::VARIABLE_KEYS));
        } catch (Throwable) {
            return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
                'status_available' => $this->field->bool('Status Available', false, criticalWhen: false),
            ])];
        }

        $maxConnections = (int)($variables['max_connections'] ?? 0);
        $maxUsed = (int)($status['Max_used_connections'] ?? 0);
        $tmpTables = (int)($status['Created_tmp_tables'] ?? 0);
        $tmpDisk = (int)($status['Created_tmp_disk_tables'] ?? 0);
        $poolReads = (int)($status['Innodb_buffer_pool_reads'] ?? 0);
        $poolRequests = (int)($status['Innodb_buffer_pool_read_requests'] ?? 0);

        $fields = [
            'status_available' => $this->field->bool('Status Available', true, criticalWhen: false),
            'uptime_hours' => $this->field->number('Uptime', (int)floor((int)($status['Uptime'] ?? 0) / 3600), unit: Unit::HOURS),
            'max_connections' => $this->field->number('Max Connections', $maxConnections),
            'threads_connected' => $this->field->number('Threads Connected', (int)($status['Threads_connected'] ?? 0)),
            'threads_running' => $this->field->number('Threads Running', (int)($status['Threads_running'] ?? 0)),
            'aborted_connects' => $this->field->number('Aborted Connects', (int)($status['Aborted_connects'] ?? 0)),
            'slow_query_log_enabled' => $this->field->bool(
                'Slow Query Log Enabled',
                in_array(strtoupper((string)($variables['slow_query_log'] ?? '')), ['ON', '1'], true)
            ),
            'innodb_buffer_pool_size_mb' => $this->field->number(
                'InnoDB Buffer Pool',
                (int)round((int)($variables['innodb_buffer_pool_size'] ?? 0) / 1048576),
                unit: Unit::MEGABYTES
            ),
            'slow_queries' => $this->field->trackableNumber(
                'Slow Queries (since start)',
                (int)($status['Slow_queries'] ?? 0),
                self::METRIC_SLOW_QUERIES,
                MetricDefinition::AGGREGATION_DELTA
            ),
            'tmp_tables_on_disk_pct' => $this->field->number(
                'Temp Tables On Disk',
                $tmpTables > 0 ? round(($tmpDisk / $tmpTables) * 100, 2) : 0.0,
                unit: Unit::PERCENT
            ),
        ];

        if ($maxConnections > 0) {
            $fields['connection_usage_pct'] = $this->field->trackableNumber(
                'Peak Connection Usage',
                round(($maxUsed / $maxConnections) * 100, 2),
                self::METRIC_CONNECTION_USAGE,
                MetricDefinition::AGGREGATION_MAX,
                unit: Unit::PERCENT
            );
        }

        $deadlocks = $this->deadlockCount($status);
        if ($deadlocks !== null) {
            $fields['innodb_deadlocks'] = $this->field->trackableNumber(
                'InnoDB Deadlocks (since start)',
                $deadlocks,
                self::METRIC_DEADLOCKS,
                MetricDefinition::AGGREGATION_DELTA
            );
        }

        if ($poolRequests > 0) {
            $fields['buffer_pool_hit_pct'] = $this->field->trackableNumber(
                'InnoDB Buffer Pool Hit Rate',
                round((1 - $poolReads / $poolRequests) * 100, 3),
                self::METRIC_BUFFER_POOL_HIT,
                MetricDefinition::AGGREGATION_MIN,
                unit: Unit::PERCENT
            );
        }

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), $fields)];
    }

    /**
     * Cumulative InnoDB deadlock count, or null when this server doesn't expose one (so no sample is
     * recorded, rather than a misleading 0). MariaDB has it as a status variable; MySQL only via the
     * lock_deadlocks counter in INNODB_METRICS, which must be enabled.
     *
     * @param array<string, string> $status
     */
    private function deadlockCount(array $status): ?int
    {
        if (isset($status['Innodb_deadlocks'])) {
            return (int)$status['Innodb_deadlocks'];
        }

        try {
            $count = $this->resource->getConnection()->fetchOne(
                "SELECT `COUNT` FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks' AND STATUS = 'enabled'"
            );
        } catch (Throwable) {
            return null;
        }

        return is_numeric($count) ? (int)$count : null;
    }

    /**
     * Alertable metrics: connection headroom, slow query growth, buffer pool misses and deadlocks.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_CONNECTION_USAGE,
                'MySQL: Peak Connection Usage',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                80,
                360,
                null,
                description: 'MySQL connections peaked at {value} of max_connections.',
                impact: 'At 100% new requests fail with "too many connections" errors.',
                criticalThreshold: 95,
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_SLOW_QUERIES,
                'MySQL: New Slow Queries',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                100,
                360,
                null,
                description: '{value} new slow queries were logged in the window.',
                impact: 'Slow queries hold connections open and are the usual cause of storefront and admin slowness.'
            ),
            new MetricDefinition(
                self::METRIC_BUFFER_POOL_HIT,
                'MySQL: InnoDB Buffer Pool Hit Rate',
                MetricDefinition::AGGREGATION_MIN,
                MetricDefinition::OPERATOR_LT,
                95,
                360,
                null,
                description: 'The InnoDB buffer pool hit rate is {value}.',
                impact: 'Reads are going to disk instead of memory; the buffer pool is likely undersized.',
                criticalThreshold: 90,
                unit: Unit::PERCENT
            ),
            new MetricDefinition(
                self::METRIC_DEADLOCKS,
                'MySQL: New InnoDB Deadlocks',
                MetricDefinition::AGGREGATION_DELTA,
                MetricDefinition::OPERATOR_GT,
                0,
                360,
                null,
                description: '{value} new InnoDB deadlocks occurred in the window.',
                impact: 'Each deadlock rolls back a transaction, so a customer or admin action fails and may need retrying.',
                criticalThreshold: 10
            ),
        ];
    }

    /**
     * Builds a SHOW GLOBAL ... query restricted to a fixed, trusted list of variable names.
     *
     * @param string $kind
     * @param list<string> $keys
     */
    private function showQuery(string $kind, array $keys): string
    {
        return sprintf("SHOW GLOBAL %s WHERE Variable_name IN ('%s')", $kind, implode("','", $keys));
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
        return 'database';
    }
}
