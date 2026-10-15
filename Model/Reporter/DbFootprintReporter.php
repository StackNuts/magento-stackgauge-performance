<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PerformanceSectionTrait;
use Throwable;

/**
 * Database size and the housekeeping tables that quietly grow without bound (visitor logs,
 * report events, search queries, cron history). Row counts come from information_schema and
 * are InnoDB estimates - good for trend and order of magnitude, not exact.
 */
class DbFootprintReporter implements
    ReporterInterface,
    DeclaresCadenceInterface,
    DeclaresSectionInterface,
    DeclaresSubjectInterface,
    MetricCatalogInterface, DeclaresSlotInterface
{
    use DailyCadenceTrait;
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_HOUSEKEEPING_MB = 'db_footprint.housekeeping_tables_mb';
    private const METRIC_NON_INNODB_TABLES = 'db_footprint.non_innodb_tables';
    private const TOP_TABLES = 10;
    private const LISTED_NON_INNODB_TABLES = 10;

    /**
     * Name suffixes of the throwaway indexer tables older Magento versions create as MEMORY tables on purpose.
     */
    private const MEMORY_TEMP_TABLE_SUFFIXES = ['_tmp', '_idx'];

    /**
     * Tables that are append-mostly logs and are safe to prune; not core business data.
     */
    private const HOUSEKEEPING_TABLES = [
        'customer_visitor', 'customer_log', 'report_event', 'report_viewed_product_index',
        'report_compared_product_index', 'search_query', 'cron_schedule', 'magento_logging_event',
        'admin_user_session', 'login_as_customer_log', 'sales_order_status_history',
    ];

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
     * Payload key for the database footprint reporter.
     */
    public function getName(): string
    {
        return 'db_footprint';
    }

    /**
     * Human-readable label for the database footprint reporter block.
     */
    public function getLabel(): string
    {
        return 'Database Footprint';
    }

    /**
     * One-line summary of what the database footprint reporter covers.
     */
    public function getDescription(): string
    {
        return 'Total database size, the largest tables, and growth of prunable log/housekeeping tables.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports database size, the largest tables and housekeeping table weight.
     */
    public function getStatus(): array
    {
        try {
            $tables = $this->resource->getConnection()->fetchAll(
                'SELECT TABLE_NAME AS name, TABLE_ROWS AS table_rows, ENGINE AS engine, TABLE_TYPE AS table_type, '
                . 'ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) AS size_mb '
                . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            );
        } catch (Throwable) {
            return ['summary' => $this->section->facts('summary', 'Summary', $this->getDescription(), [
                'footprint_available' => $this->field->bool('Footprint Available', false, criticalWhen: false),
            ])];
        }

        $housekeeping = array_map(fn (string $table): string => $this->resource->getTableName($table), self::HOUSEKEEPING_TABLES);
        $total = 0.0;
        $housekeepingMb = 0.0;
        foreach ($tables as $table) {
            $total += (float)$table['size_mb'];
            if (in_array($table['name'], $housekeeping, true)) {
                $housekeepingMb += (float)$table['size_mb'];
            }
        }

        $nonInnodb = array_values(array_filter($tables, $this->isNonInnodbBaseTable(...)));

        usort($tables, static fn (array $a, array $b): int => (float)$b['size_mb'] <=> (float)$a['size_mb']);
        $rows = [];
        foreach (array_slice($tables, 0, self::TOP_TABLES) as $table) {
            $rows[] = $this->field->array($table['name'], [
                'name' => $this->field->varchar('Table', $table['name']),
                'rows' => $this->field->number('Rows (estimate)', (int)$table['table_rows']),
                'size_mb' => $this->field->number('Size', (float)$table['size_mb'], unit: Unit::MEGABYTES),
            ]);
        }

        return [
            'summary' => $this->section->facts('summary', 'Summary', $this->getDescription(), [
                'footprint_available' => $this->field->bool('Footprint Available', true, criticalWhen: false),
                'table_count' => $this->field->number('Tables', count($tables)),
                'total_size_mb' => $this->field->number('Total Size', round($total, 1), unit: Unit::MEGABYTES),
                'housekeeping_tables_mb' => $this->field->trackableNumber(
                    'Prunable Log Tables',
                    round($housekeepingMb, 1),
                    self::METRIC_HOUSEKEEPING_MB,
                    MetricDefinition::AGGREGATION_MAX,
                    unit: Unit::MEGABYTES
                ),
                'non_innodb_tables' => $this->field->trackableNumber(
                    'Non-InnoDB Tables',
                    count($nonInnodb),
                    self::METRIC_NON_INNODB_TABLES,
                    MetricDefinition::AGGREGATION_MAX
                ),
            ]),
            'largest_tables' => $this->section->table(
                'largest_tables',
                'Largest Tables',
                $this->getDescription(),
                $rows
            ),
            'non_innodb_tables' => $this->section->table(
                'non_innodb_tables',
                'Non-InnoDB Tables',
                'Base tables not using InnoDB (first ' . self::LISTED_NON_INNODB_TABLES . ').',
                array_map(
                    fn (array $table) => $this->field->array($table['name'], [
                        'name' => $this->field->varchar('Table', $table['name']),
                        'engine' => $this->field->varchar('Engine', (string)$table['engine']),
                    ]),
                    array_slice($nonInnodb, 0, self::LISTED_NON_INNODB_TABLES)
                )
            ),
        ];
    }

    /**
     * Base tables (not views) on a non-InnoDB engine, excluding MEMORY indexer temp tables
     * (names ending in _tmp/_idx) that Magento creates that way intentionally.
     *
     * @param array<string, mixed> $table
     */
    private function isNonInnodbBaseTable(array $table): bool
    {
        $engine = (string)($table['engine'] ?? '');
        if (($table['table_type'] ?? 'BASE TABLE') !== 'BASE TABLE' || $engine === '' || strcasecmp($engine, 'InnoDB') === 0) {
            return false;
        }

        if (strcasecmp($engine, 'MEMORY') === 0) {
            foreach (self::MEMORY_TEMP_TABLE_SUFFIXES as $suffix) {
                if (str_ends_with((string)$table['name'], $suffix)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Alertable metrics: combined size of prunable log tables, and tables not on InnoDB.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_HOUSEKEEPING_MB,
                'Database: Prunable Log Tables',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                1024,
                1440,
                null,
                description: 'Log and housekeeping tables have grown to {value}.',
                impact: 'Unpruned logs bloat backups and slow queries; check cleanup cron and retention settings.',
                criticalThreshold: 5120,
                unit: Unit::MEGABYTES
            ),
            new MetricDefinition(
                self::METRIC_NON_INNODB_TABLES,
                'Database: Non-InnoDB Tables',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                0,
                1440,
                null,
                description: '{value} tables are not using the InnoDB storage engine.',
                impact: 'MyISAM and similar engines lock whole tables and are not crash-safe, which causes slow writes and data loss after a crash.'
            ),
        ];
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
