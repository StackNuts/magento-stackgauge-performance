<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGaugePerformance\Model\Reporter\MysqlRuntimeReporter;

class MysqlRuntimeReporterTest extends TestCase
{
    public function testComputesRatiosFromStatusAndVariables(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchPairs')->willReturnOnConsecutiveCalls(
            [
                'Threads_connected' => '30', 'Max_used_connections' => '80', 'Slow_queries' => '12', 'Created_tmp_tables' => '200',
                'Created_tmp_disk_tables' => '50', 'Innodb_buffer_pool_reads' => '10',
                'Innodb_buffer_pool_read_requests' => '1000', 'Uptime' => '7200',
            ],
            ['max_connections' => '100', 'innodb_buffer_pool_size' => '2097152', 'slow_query_log' => 'ON']
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $fields = (new MysqlRuntimeReporter($resource, new Field(), new Section()))
            ->getStatus()['general']->getFields();

        // Current connections drive the metric; the since-start peak is display-only and can't hold the alert open.
        $this->assertSame(30.0, $fields['connection_usage_pct']->getValue());
        $this->assertSame(80, $fields['max_used_connections']->getValue());
        $this->assertSame(25.0, $fields['tmp_tables_on_disk_pct']->getValue());
        $this->assertSame(99.0, $fields['buffer_pool_hit_pct']->getValue());
        $this->assertSame(12, $fields['slow_queries']->getValue());
        $this->assertTrue($fields['slow_query_log_enabled']->getValue());
        $this->assertSame(2, $fields['uptime_hours']->getValue());
    }

    public function testReportsMariaDbDeadlockCounter(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchPairs')->willReturnOnConsecutiveCalls(['Innodb_deadlocks' => '7'], []);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $fields = (new MysqlRuntimeReporter($resource, new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertSame(7, $fields['innodb_deadlocks']->getValue());
    }

    public function testFallsBackToInnodbMetricsAndOmitsDeadlocksWhenUnavailable(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchPairs')->willReturn([]);
        $connection->method('fetchOne')->willReturn('3');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $fields = (new MysqlRuntimeReporter($resource, new Field(), new Section()))
            ->getStatus()['general']->getFields();
        $this->assertSame(3, $fields['innodb_deadlocks']->getValue());

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchPairs')->willReturn([]);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('no table'));
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $fields = (new MysqlRuntimeReporter($resource, new Field(), new Section()))
            ->getStatus()['general']->getFields();
        $this->assertArrayNotHasKey('innodb_deadlocks', $fields);
    }

    public function testReportsUnavailableWhenQueryThrows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchPairs')->willThrowException(new \RuntimeException('denied'));
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $fields = (new MysqlRuntimeReporter($resource, new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertSame(['status_available'], array_keys($fields));
        $this->assertFalse($fields['status_available']->getValue());
    }

    public function testDeclaresFourMetrics(): void
    {
        $reporter = new MysqlRuntimeReporter($this->createMock(ResourceConnection::class), new Field(), new Section());

        $this->assertCount(4, $reporter->getTrackableMetrics());
    }
}
