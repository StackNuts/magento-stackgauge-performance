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
        $store = $this->createMock(WebRuntimeStore::class);
        $store->method('read')->willReturn(null);

        $fields = (new PhpRuntimeReporter($store, new Field(), new Section()))->getStatus()['general']->getFields();

        $this->assertSame(['snapshot_available'], array_keys($fields));
        $this->assertFalse($fields['snapshot_available']->getValue());
    }

    public function testReportsOpcacheMetricsFromSnapshot(): void
    {
        $store = $this->createMock(WebRuntimeStore::class);
        $store->method('read')->willReturn([
            'collected_at' => time() - 600,
            'sapi' => 'fpm-fcgi',
            'php_version' => '8.3.1',
            'memory_limit' => '2G',
            'realpath_cache' => ['used_bytes' => 512, 'limit_bytes' => 1024],
            'opcache' => [
                'hit_rate' => 99.5, 'memory_used_pct' => 42.0, 'wasted_pct' => 1.0, 'keys_used_pct' => 10.0,
                'interned_strings_used_pct' => 20.0, 'cached_scripts' => 5000, 'oom_restarts' => 2,
                'cache_full' => false, 'validate_timestamps' => false, 'jit_enabled' => false,
            ],
        ]);

        $fields = (new PhpRuntimeReporter($store, new Field(), new Section()))->getStatus()['general']->getFields();

        $this->assertTrue($fields['opcache_enabled']->getValue());
        $this->assertSame(99.5, $fields['opcache_hit_rate']->getValue());
        $this->assertSame(2, $fields['opcache_oom_restarts']->getValue());
        $this->assertSame(50.0, $fields['realpath_cache_used_pct']->getValue());
        $this->assertSame(10, $fields['snapshot_age_minutes']->getValue());
    }

    public function testOmitsOpcacheFieldsWhenOpcacheDisabled(): void
    {
        $store = $this->createMock(WebRuntimeStore::class);
        $store->method('read')->willReturn(['collected_at' => time(), 'opcache' => null]);

        $fields = (new PhpRuntimeReporter($store, new Field(), new Section()))->getStatus()['general']->getFields();

        $this->assertFalse($fields['opcache_enabled']->getValue());
        $this->assertArrayNotHasKey('opcache_hit_rate', $fields);
    }

    public function testDeclaresThreeMetricsMatchingItsFields(): void
    {
        $reporter = new PhpRuntimeReporter($this->createMock(WebRuntimeStore::class), new Field(), new Section());

        $this->assertCount(3, $reporter->getTrackableMetrics());
    }
}
