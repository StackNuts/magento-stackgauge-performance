<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGaugePerformance\Model\Reporter\PhpRuntimeReporter;
use StackNuts\StackGaugePerformance\Model\Runtime\WebRuntimeStore;

class PhpRuntimeReporterTest extends TestCase
{
    public function testReportsNoSnapshotWhenStoreIsEmpty(): void
    {
        $fields = $this->statusFor([])['general']->getFields();

        $this->assertSame(['snapshot_available'], array_keys($fields));
        $this->assertFalse($fields['snapshot_available']->getValue());
    }

    public function testReportsOpcacheMetricsFromSnapshot(): void
    {
        $fields = $this->statusFor(['web1' => $this->snapshot(['collected_at' => time() - 600, 'sapi' => 'fpm-fcgi',
            'php_version' => '8.3.1', 'memory_limit' => '2G', 'opcache' => $this->opcache(['oom_restarts' => 2, 'hit_rate' => 99.5])])])
            ['general']->getFields();

        $this->assertTrue($fields['opcache_enabled']->getValue());
        $this->assertSame(99.5, $fields['opcache_hit_rate']->getValue());
        $this->assertSame(2, $fields['opcache_oom_restarts']->getValue());
        $this->assertSame(50.0, $fields['realpath_cache_used_pct']->getValue());
        $this->assertSame(10, $fields['snapshot_age_minutes']->getValue());
        $this->assertFalse($fields['snapshot_stale']->getValue());
    }

    public function testOmitsOpcacheFieldsWhenOpcacheDisabled(): void
    {
        $fields = $this->statusFor(['web1' => $this->snapshot(['opcache' => null])])['general']->getFields();

        $this->assertFalse($fields['opcache_enabled']->getValue());
        $this->assertArrayNotHasKey('opcache_hit_rate', $fields);
    }

    public function testDeclaresSevenMetricsMatchingItsFields(): void
    {
        $reporter = new PhpRuntimeReporter($this->createMock(WebRuntimeStore::class), new Field(), new Section());

        $this->assertCount(7, $reporter->getTrackableMetrics());
    }

    public function testRealpathCacheUsedPctIsTrackable(): void
    {
        $fields = $this->statusFor(['web1' => $this->snapshot()])['general']->getFields();

        $this->assertSame('php_runtime.realpath_cache_used_pct', $fields['realpath_cache_used_pct']->getMetricKey());
        $this->assertSame('php_runtime.opcache_wasted_pct', $fields['opcache_wasted_pct']->getMetricKey());
        $this->assertSame('php_runtime.opcache_keys_used_pct', $fields['opcache_keys_used_pct']->getMetricKey());
        $this->assertSame(
            'php_runtime.opcache_interned_strings_used_pct',
            $fields['opcache_interned_strings_used_pct']->getMetricKey()
        );
    }

    public function testStaleSnapshotEmitsNoMetricsAndIsMarkedStale(): void
    {
        $status = $this->statusFor(['web1' => $this->snapshot(['collected_at' => time() - 3 * 3600])]);
        $fields = $status['general']->getFields();

        $this->assertTrue($fields['snapshot_stale']->getValue());
        $this->assertArrayNotHasKey('opcache_hit_rate', $fields);
        $this->assertArrayNotHasKey('realpath_cache_used_pct', $fields);
        $this->assertArrayNotHasKey('opcache_oom_restarts', $fields);
        $this->assertCount(1, $status['hosts']->getRows());
    }

    public function testHitRateIsSkippedWhileOpcacheIsWarmingUp(): void
    {
        $now = time();
        $warming = $this->snapshot(['collected_at' => $now, 'opcache' => $this->opcache(['warm_since' => $now - 120, 'hit_rate' => 55.0])]);
        $fields = $this->statusFor(['web1' => $warming])['general']->getFields();

        $this->assertArrayNotHasKey('opcache_hit_rate', $fields);
        $this->assertTrue($fields['opcache_warming_up']->getValue());
        $this->assertArrayHasKey('opcache_memory_used_pct', $fields);

        $warm = $this->snapshot(['collected_at' => $now, 'opcache' => $this->opcache(['warm_since' => $now - 3600, 'hit_rate' => 97.0])]);
        $fields = $this->statusFor(['web1' => $warm])['general']->getFields();

        $this->assertSame(97.0, $fields['opcache_hit_rate']->getValue());
        $this->assertFalse($fields['opcache_warming_up']->getValue());
    }

    public function testAQuietOpcacheRunningForHoursStillNeedsEnoughAccesses(): void
    {
        $now = time();
        $quiet = $this->snapshot(['collected_at' => $now, 'opcache' => $this->opcache(['warm_since' => $now - 86400, 'accesses' => 30000, 'hit_rate' => 60.0])]);
        $busy = $this->snapshot(['collected_at' => $now, 'opcache' => $this->opcache(['warm_since' => $now - 86400, 'accesses' => 900000, 'hit_rate' => 99.0])]);

        $this->assertArrayNotHasKey('opcache_hit_rate', $this->statusFor(['a' => $quiet])['general']->getFields());
        $this->assertSame(99.0, $this->statusFor(['a' => $busy])['general']->getFields()['opcache_hit_rate']->getValue());
    }

    public function testInternedStringsUsageIsOnlyEverAWarning(): void
    {
        $definitions = array_filter(
            (new PhpRuntimeReporter($this->createMock(WebRuntimeStore::class), new Field(), new Section()))->getTrackableMetrics(),
            static fn ($definition): bool => $definition->getMetricKey() === 'php_runtime.opcache_interned_strings_used_pct'
        );

        $this->assertNull(array_values($definitions)[0]->getCriticalThreshold());
    }

    public function testWithoutStartTimeWarmUpFallsBackToAccessCount(): void
    {
        $few = $this->snapshot(['opcache' => $this->opcache(['warm_since' => 0, 'accesses' => 50])]);
        $many = $this->snapshot(['opcache' => $this->opcache(['warm_since' => 0, 'accesses' => 500000])]);

        $this->assertArrayNotHasKey('opcache_hit_rate', $this->statusFor(['a' => $few])['general']->getFields());
        $this->assertArrayHasKey('opcache_hit_rate', $this->statusFor(['a' => $many])['general']->getFields());
    }

    public function testMultipleNodesAreAggregatedWorstCaseAndListedPerHost(): void
    {
        $a = $this->snapshot(['opcache' => $this->opcache(['hit_rate' => 99.0, 'memory_used_pct' => 40.0, 'oom_restarts' => 1])]);
        $b = $this->snapshot(['opcache' => $this->opcache(['hit_rate' => 90.0, 'memory_used_pct' => 70.0, 'oom_restarts' => 2])]);
        $stale = $this->snapshot(['collected_at' => time() - 86400, 'opcache' => $this->opcache(['hit_rate' => 1.0, 'oom_restarts' => 50])]);

        $status = $this->statusFor(['web1' => $a, 'web2' => $b, 'old' => $stale]);
        $fields = $status['general']->getFields();

        $this->assertSame(90.0, $fields['opcache_hit_rate']->getValue());
        $this->assertSame(70.0, $fields['opcache_memory_used_pct']->getValue());
        $this->assertSame(3, $fields['opcache_oom_restarts']->getValue());
        $this->assertFalse($fields['snapshot_stale']->getValue());
        $rows = $status['hosts']->getRows();
        $this->assertCount(3, $rows);
        $this->assertTrue($rows[2]->getValue()['stale']->getValue());
    }

    /**
     * @param array<string, array<string,mixed>> $snapshots
     * @return array<string, mixed>
     */
    private function statusFor(array $snapshots): array
    {
        $store = $this->createMock(WebRuntimeStore::class);
        $store->method('readAll')->willReturn($snapshots);

        return (new PhpRuntimeReporter($store, new Field(), new Section()))->getStatus();
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        return $overrides + [
            'collected_at' => time(),
            'realpath_cache' => ['used_bytes' => 512, 'limit_bytes' => 1024],
            'opcache' => $this->opcache(),
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function opcache(array $overrides = []): array
    {
        return $overrides + [
            'hit_rate' => 99.5, 'memory_used_pct' => 42.0, 'wasted_pct' => 1.0, 'keys_used_pct' => 10.0,
            'interned_strings_used_pct' => 20.0, 'cached_scripts' => 5000, 'oom_restarts' => 0,
            'cache_full' => false, 'validate_timestamps' => false, 'jit_enabled' => false,
        ];
    }
}
