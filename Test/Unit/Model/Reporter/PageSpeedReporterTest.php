<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGaugePerformance\Model\Probe\KeyPageDiscovery;
use StackNuts\StackGaugePerformance\Model\Probe\PageTimer;
use StackNuts\StackGaugePerformance\Model\Reporter\PageSpeedReporter;

class PageSpeedReporterTest extends TestCase
{
    public function testReportsTtfbPerPageAndSkipsMetricsForUnreachablePages(): void
    {
        $discovery = $this->createMock(KeyPageDiscovery::class);
        $discovery->method('discover')->willReturn([
            'category' => ['url' => 'https://shop.test/cat.html', 'source' => 'auto'],
            'product' => ['url' => 'https://shop.test/p.html', 'source' => 'pinned'],
            'cart' => ['url' => 'https://shop.test/checkout/cart/', 'source' => 'auto'],
        ]);
        $timer = $this->createMock(PageTimer::class);
        $timer->method('time')->willReturnMap([
            ['https://shop.test/cat.html', ['status' => 200, 'ttfb_ms' => 120, 'total_ms' => 300, 'cache' => 'hit']],
            ['https://shop.test/p.html', ['status' => 200, 'ttfb_ms' => 900, 'total_ms' => 1100, 'cache' => 'miss']],
            ['https://shop.test/checkout/cart/', ['status' => 0, 'ttfb_ms' => 0, 'total_ms' => 0, 'cache' => 'unknown']],
        ]);

        $status = (new PageSpeedReporter($discovery, $timer, new Field(), new Section()))->getStatus();
        $summary = $status['summary']->getFields();

        $this->assertSame(120, $summary['category_ttfb_ms']->getValue());
        $this->assertSame(900, $summary['product_ttfb_ms']->getValue());
        $this->assertArrayNotHasKey('cart_ttfb_ms', $summary);
        $this->assertSame(3, $summary['pages_timed']->getValue());
        $this->assertCount(3, $status['pages']->getRows());
        $this->assertSame('pinned', $status['pages']->getRows()[1]->getValue()['source']->getValue());
    }

    public function testNoPagesWhenDiscoveryFindsNothing(): void
    {
        $discovery = $this->createMock(KeyPageDiscovery::class);
        $discovery->method('discover')->willReturn([]);

        $status = (new PageSpeedReporter($discovery, $this->createMock(PageTimer::class), new Field(), new Section()))
            ->getStatus();

        $this->assertSame(0, $status['summary']->getFields()['pages_timed']->getValue());
    }

    public function testDeclaresOneMetricPerPageType(): void
    {
        $reporter = new PageSpeedReporter(
            $this->createMock(KeyPageDiscovery::class),
            $this->createMock(PageTimer::class),
            new Field(),
            new Section()
        );

        $this->assertCount(4, $reporter->getTrackableMetrics());
    }
}
