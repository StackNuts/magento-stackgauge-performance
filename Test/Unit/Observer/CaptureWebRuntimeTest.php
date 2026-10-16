<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGaugePerformance\Model\Runtime\WebRuntimeCollector;
use StackNuts\StackGaugePerformance\Model\Runtime\WebRuntimeStore;
use StackNuts\StackGaugePerformance\Observer\CaptureWebRuntime;

class CaptureWebRuntimeTest extends TestCase
{
    public function testWritesASnapshotWhenSampledAndStale(): void
    {
        $collector = $this->createMock(WebRuntimeCollector::class);
        $collector->method('collect')->willReturn(['collected_at' => 1]);
        $store = $this->createMock(WebRuntimeStore::class);
        $store->method('isStale')->willReturn(true);
        $store->expects($this->atLeastOnce())->method('write')->with(['collected_at' => 1]);

        $observer = new CaptureWebRuntime($collector, $store);
        // The 1-in-25 sample is random, so drive enough requests that it fires with certainty.
        for ($i = 0; $i < 2000; $i++) {
            $observer->execute(new Observer());
        }
    }

    public function testNeverWritesWhileTheSnapshotIsFresh(): void
    {
        $collector = $this->createMock(WebRuntimeCollector::class);
        $collector->expects($this->never())->method('collect');
        $store = $this->createMock(WebRuntimeStore::class);
        $store->method('isStale')->willReturn(false);
        $store->expects($this->never())->method('write');

        $observer = new CaptureWebRuntime($collector, $store);
        for ($i = 0; $i < 500; $i++) {
            $observer->execute(new Observer());
        }
    }

    public function testSwallowsEveryFailure(): void
    {
        $collector = $this->createMock(WebRuntimeCollector::class);
        $collector->method('collect')->willThrowException(new \RuntimeException('boom'));
        $store = $this->createMock(WebRuntimeStore::class);
        $store->method('isStale')->willThrowException(new \RuntimeException('stat failed'));

        $observer = new CaptureWebRuntime($collector, $store);
        for ($i = 0; $i < 200; $i++) {
            $observer->execute(new Observer());
        }

        $this->addToAssertionCount(1);
    }
}
