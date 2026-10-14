<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Plugin;

use Magento\Framework\App\Request\Http;
use Magento\Search\Model\Query;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGaugePerformance\Plugin\SkipProbeSearchStats;

class SkipProbeSearchStatsTest extends TestCase
{
    /**
     * @param string|false $header
     */
    private function plugin(string|false $header): SkipProbeSearchStats
    {
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with('X-StackGauge-Probe')->willReturn($header);

        return new SkipProbeSearchStats($request);
    }

    public function testAProbeRequestDoesNotCountTowardsSearchStats(): void
    {
        $query = $this->createMock(Query::class);
        $proceeded = false;
        $proceed = function () use (&$proceeded, $query) {
            $proceeded = true;
            return $query;
        };

        $plugin = $this->plugin('1');
        $plugin->aroundSaveIncrementalPopularity($query, $proceed);
        $plugin->aroundSaveNumResults($query, $proceed, 12);

        $this->assertFalse($proceeded);
    }

    public function testARealVisitorsSearchIsCounted(): void
    {
        $query = $this->createMock(Query::class);
        $calls = 0;
        $proceed = function () use (&$calls, $query) {
            $calls++;
            return $query;
        };

        $plugin = $this->plugin(false);
        $plugin->aroundSaveIncrementalPopularity($query, $proceed);
        $plugin->aroundSaveNumResults($query, $proceed, 12);

        $this->assertSame(2, $calls);
    }
}
