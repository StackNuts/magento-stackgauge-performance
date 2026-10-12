<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\PerformanceSectionTrait;
use Throwable;

/**
 * Cron job duration and scheduling health from cron_schedule: how long jobs run, how late they
 * start, and how many are stuck, missed or errored. Only as deep as Magento's cron history
 * retention (about an hour for successes by default), so these are recent-window figures.
 * Times are compared in SQL against UTC_TIMESTAMP() to sidestep PHP's mutable default timezone.
 */
class CronPerformanceReporter implements ReporterInterface, DeclaresSectionInterface, MetricCatalogInterface
{
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const STUCK_AFTER_MINUTES = 30;
    private const LATE_AFTER_MINUTES = 5;
    private const METRIC_MAX_DURATION = 'cron_perf.max_job_duration_seconds';
    private const METRIC_STUCK_JOBS = 'cron_perf.stuck_jobs';
    private const METRIC_SCHEDULE_LAG = 'cron_perf.schedule_lag_seconds';
    private const METRIC_ERROR_JOBS = 'cron_perf.error_jobs';

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
     * Payload key for the cron performance reporter.
     */
    public function getName(): string
    {
        return 'cron_perf';
    }

    /**
     * Human-readable label for the cron performance reporter block.
     */
    public function getLabel(): string
    {
        return 'Cron Performance';
    }

    /**
     * One-line summary of what the cron performance reporter covers.
     */
    public function getDescription(): string
    {
        return 'Cron job duration, start lateness, stuck, missed and errored jobs from recent cron history.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports duration and scheduling health from cron_schedule.
     */
    public function getStatus(): array
    {
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('cron_schedule');

            $stats = (array)$connection->fetchRow(sprintf(
                "SELECT
                    SUM(status = 'missed') AS missed,
                    SUM(status = 'error') AS errors,
                    SUM(status = 'running' AND executed_at < UTC_TIMESTAMP() - INTERVAL %d MINUTE) AS stuck,
                    MAX(IF(status = 'success', TIMESTAMPDIFF(SECOND, executed_at, finished_at), NULL)) AS max_duration,
                    AVG(IF(status = 'success', TIMESTAMPDIFF(SECOND, executed_at, finished_at), NULL)) AS avg_duration,
                    MAX(IF(status = 'pending' AND scheduled_at < UTC_TIMESTAMP() - INTERVAL %d MINUTE,
                        TIMESTAMPDIFF(SECOND, scheduled_at, UTC_TIMESTAMP()), 0)) AS lag
                 FROM %s",
                self::STUCK_AFTER_MINUTES,
                self::LATE_AFTER_MINUTES,
                $table
            ));

            $slowest = (string)$connection->fetchOne(sprintf(
                "SELECT job_code FROM %s WHERE status = 'success' AND executed_at IS NOT NULL
                 AND finished_at IS NOT NULL ORDER BY TIMESTAMPDIFF(SECOND, executed_at, finished_at) DESC LIMIT 1",
                $table
            ));
        } catch (Throwable) {
            return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
                'history_available' => $this->field->bool('Cron History Available', false, criticalWhen: false),
            ])];
        }

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
            'history_available' => $this->field->bool('Cron History Available', true, criticalWhen: false),
            'max_job_duration_seconds' => $this->field->trackableNumber(
                'Longest Job Duration (s)',
                (int)($stats['max_duration'] ?? 0),
                self::METRIC_MAX_DURATION,
                MetricDefinition::AGGREGATION_MAX
            ),
            'slowest_job_code' => $this->field->varchar('Slowest Job', $slowest),
            'avg_job_duration_seconds' => $this->field->number(
                'Average Job Duration (s)',
                round((float)($stats['avg_duration'] ?? 0), 1)
            ),
            'schedule_lag_seconds' => $this->field->trackableNumber(
                'Longest Start Lateness (s)',
                (int)($stats['lag'] ?? 0),
                self::METRIC_SCHEDULE_LAG,
                MetricDefinition::AGGREGATION_MAX
            ),
            'stuck_jobs' => $this->field->trackableNumber(
                'Stuck Running Jobs',
                (int)($stats['stuck'] ?? 0),
                self::METRIC_STUCK_JOBS,
                MetricDefinition::AGGREGATION_MAX
            ),
            'missed_jobs' => $this->field->number('Missed Jobs', (int)($stats['missed'] ?? 0)),
            'error_jobs' => $this->field->trackableNumber(
                'Errored Jobs',
                (int)($stats['errors'] ?? 0),
                self::METRIC_ERROR_JOBS,
                MetricDefinition::AGGREGATION_MAX
            ),
        ])];
    }

    /**
     * Alertable metrics: long jobs, stuck jobs, late starts and errored jobs.
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_MAX_DURATION,
                'Cron: Longest Job Duration (s)',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                900,
                120,
                null,
                description: 'A cron job ran for {value} seconds.',
                impact: 'Long-running jobs delay everything queued behind them in the same cron group.'
            ),
            new MetricDefinition(
                self::METRIC_STUCK_JOBS,
                'Cron: Stuck Running Jobs',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                0,
                120,
                null,
                description: '{value} cron job(s) have been "running" for over 30 minutes.',
                impact: 'A job stuck in running usually means a crashed process and blocks its next scheduled run.'
            ),
            new MetricDefinition(
                self::METRIC_SCHEDULE_LAG,
                'Cron: Longest Start Lateness (s)',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                900,
                120,
                null,
                description: 'A cron job is {value} seconds past its scheduled start.',
                impact: 'Cron is falling behind, so indexing, emails and queues are delayed.'
            ),
            new MetricDefinition(
                self::METRIC_ERROR_JOBS,
                'Cron: Errored Jobs',
                MetricDefinition::AGGREGATION_MAX,
                MetricDefinition::OPERATOR_GT,
                5,
                120,
                null,
                description: '{value} cron job(s) ended in error recently.',
                impact: 'Failing jobs mean scheduled work (indexing, imports, emails) is not completing.'
            ),
        ];
    }
}
