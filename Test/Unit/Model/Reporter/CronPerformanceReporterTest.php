<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGaugePerformance\Model\Reporter\CronPerformanceReporter;

class CronPerformanceReporterTest extends TestCase
{
    public function testReportsDurationAndSchedulingStats(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchRow')->willReturn([
            'missed' => '3', 'errors' => '1', 'stuck' => '2', 'max_duration' => '640',
            'avg_duration' => '12.34', 'lag' => '420',
        ]);
        $connection->method('fetchOne')->willReturn('indexer_reindex_all_invalid');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('cron_schedule');

        $fields = (new CronPerformanceReporter($resource, new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertSame(640, $fields['max_job_duration_seconds']->getValue());
        $this->assertSame('indexer_reindex_all_invalid', $fields['slowest_job_code']->getValue());
        $this->assertSame(12.3, $fields['avg_job_duration_seconds']->getValue());
        $this->assertSame(420, $fields['schedule_lag_seconds']->getValue());
        $this->assertSame(2, $fields['stuck_jobs']->getValue());
        $this->assertSame(3, $fields['missed_jobs']->getValue());
        $this->assertSame(1, $fields['error_jobs']->getValue());
    }

    public function testBothQueriesAreLimitedToRecentHistory(): void
    {
        $queries = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchRow')->willReturnCallback(static function (string $sql) use (&$queries): array {
            $queries[] = $sql;

            return [];
        });
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql) use (&$queries): string {
            $queries[] = $sql;

            return '';
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('cron_schedule');

        (new CronPerformanceReporter($resource, new Field(), new Section()))->getStatus();

        $this->assertCount(2, $queries);
        foreach ($queries as $sql) {
            $this->assertStringContainsString('scheduled_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR', $sql);
        }
    }

    public function testReportsUnavailableWhenQueryThrows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchRow')->willThrowException(new \RuntimeException('gone'));
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('cron_schedule');

        $fields = (new CronPerformanceReporter($resource, new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertSame(['history_available'], array_keys($fields));
    }

    public function testDeclaresFourMetrics(): void
    {
        $reporter = new CronPerformanceReporter(
            $this->createMock(ResourceConnection::class),
            new Field(),
            new Section()
        );

        $this->assertCount(4, $reporter->getTrackableMetrics());
    }
}
