<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Runtime;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGaugePerformance\Model\Runtime\WebRuntimeCollector;

class WebRuntimeCollectorTest extends TestCase
{
    public function testCollectReturnsRuntimeBasics(): void
    {
        $snapshot = (new WebRuntimeCollector())->collect();

        $this->assertSame(PHP_VERSION, $snapshot['php_version']);
        $this->assertArrayHasKey('opcache', $snapshot);
        $this->assertArrayHasKey('used_bytes', $snapshot['realpath_cache']);
        $this->assertIsInt($snapshot['collected_at']);
    }
}
