<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGaugePerformance\Model\Reporter\RedisPerformanceReporter;

class RedisPerformanceReporterTest extends TestCase
{
    public function testReportsZeroInstancesWhenNoRedisBackendsConfigured(): void
    {
        $config = $this->createMock(DeploymentConfig::class);
        $config->method('get')->willReturn([]);

        $status = (new RedisPerformanceReporter($config, new Field(), new Section()))->getStatus();
        $fields = $status['summary']->getFields();

        $this->assertSame(0, $fields['instances_checked']->getValue());
        $this->assertSame(0, $fields['evicted_keys']->getValue());
        $this->assertArrayNotHasKey('memory_used_pct', $fields);
        $this->assertArrayNotHasKey('hit_rate', $fields);
    }

    public function testDeclaresThreeMetrics(): void
    {
        $reporter = new RedisPerformanceReporter(
            $this->createMock(DeploymentConfig::class),
            new Field(),
            new Section()
        );

        $this->assertCount(3, $reporter->getTrackableMetrics());
    }
}
