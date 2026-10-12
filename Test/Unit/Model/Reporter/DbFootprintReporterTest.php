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
