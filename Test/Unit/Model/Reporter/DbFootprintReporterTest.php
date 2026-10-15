<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGaugePerformance\Model\Reporter\DbFootprintReporter;

class DbFootprintReporterTest extends TestCase
{
    public function testSumsSizesAndSeparatesHousekeepingTables(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([
            ['name' => 'sales_order', 'table_rows' => '100', 'size_mb' => '50.5'],
            ['name' => 'customer_visitor', 'table_rows' => '9000', 'size_mb' => '200.0'],
            ['name' => 'cron_schedule', 'table_rows' => '500', 'size_mb' => '1.5'],
        ]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $fields = (new DbFootprintReporter($resource, new Field(), new Section()))
            ->getStatus()['summary']->getFields();

        $this->assertSame(252.0, $fields['total_size_mb']->getValue());
        $this->assertSame(201.5, $fields['housekeeping_tables_mb']->getValue());
        $this->assertSame(3, $fields['table_count']->getValue());
    }

    public function testCountsNonInnodbBaseTablesExcludingViewsAndMemoryTempTables(): void
    {
        $row = static fn (string $name, ?string $engine, string $type = 'BASE TABLE'): array => [
            'name' => $name, 'table_rows' => '1', 'size_mb' => '1.0', 'engine' => $engine, 'table_type' => $type,
        ];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([
            $row('sales_order', 'InnoDB'),
            $row('legacy_log', 'MyISAM'),
            $row('old_cache', 'Aria'),
            $row('catalog_product_index_price_tmp', 'MEMORY'),
            $row('catalog_category_product_index_idx', 'MEMORY'),
            $row('custom_memory', 'MEMORY'),
            $row('inventory_stock_1', null, 'VIEW'),
        ]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $status = (new DbFootprintReporter($resource, new Field(), new Section()))->getStatus();

        $this->assertSame(3, $status['summary']->getFields()['non_innodb_tables']->getValue());
        $this->assertArrayHasKey('non_innodb_tables', $status);
    }

    public function testDeclaresTwoMetrics(): void
    {
        $reporter = new DbFootprintReporter($this->createMock(ResourceConnection::class), new Field(), new Section());

        $this->assertCount(2, $reporter->getTrackableMetrics());
    }

    public function testReportsUnavailableWhenQueryThrows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchAll')->willThrowException(new \RuntimeException('denied'));
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $fields = (new DbFootprintReporter($resource, new Field(), new Section()))
            ->getStatus()['summary']->getFields();

        $this->assertSame(['footprint_available'], array_keys($fields));
    }
}
