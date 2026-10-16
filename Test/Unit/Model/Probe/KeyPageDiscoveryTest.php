<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Probe;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGaugePerformance\Model\Probe\KeyPageDiscovery;

class KeyPageDiscoveryTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    /** @var array<string,string> */
    private array $cache = [];

    public function testUsesTheSecureLinkBaseUrlAndTheFixedPaths(): void
    {
        $pages = $this->discovery(['cat.html', 'p.html'])->discover();

        $this->assertSame('https://shop.test/cat.html', $pages['category']['url']);
        $this->assertSame('https://shop.test/p.html', $pages['product']['url']);
        $this->assertSame('https://shop.test/catalogsearch/result/?q=product', $pages['search']['url']);
        $this->assertSame('https://shop.test/checkout/cart/', $pages['cart']['url']);
        $this->assertSame('auto', $pages['cart']['source']);
    }

    public function testPicksAreRestrictedToEnabledVisibleProductsAndActiveCategories(): void
    {
        $this->discovery(['cat.html', 'p.html'])->discover();

        $this->assertCount(2, $this->queries);
        $this->assertStringContainsString("attribute_code = 'is_active'", $this->queries[0]);
        $this->assertStringContainsString("attribute_code = 'status'", $this->queries[1]);
        $this->assertStringContainsString("attribute_code = 'visibility'", $this->queries[1]);
    }

    public function testPicksAreCachedAndNotRequeried(): void
    {
        $discovery = $this->discovery(['cat.html', 'p.html']);
        $discovery->discover();
        $queriesAfterFirstRun = count($this->queries);
        $second = $discovery->discover();

        $this->assertSame($queriesAfterFirstRun, count($this->queries));
        $this->assertSame('https://shop.test/p.html', $second['product']['url']);
    }

    public function testFallsBackToTheUnrestrictedPickWhenTheFilteredOneFindsNothing(): void
    {
        $pages = $this->discovery([null, 'cat.html', null, 'p.html'])->discover();

        $this->assertCount(4, $this->queries);
        $this->assertStringNotContainsString("attribute_code = 'status'", $this->queries[3]);
        $this->assertSame('https://shop.test/p.html', $pages['product']['url']);
    }

    public function testMissingPickIsOmittedAndNotCached(): void
    {
        $pages = $this->discovery([null, null, null, null])->discover();

        $this->assertArrayNotHasKey('category', $pages);
        $this->assertArrayNotHasKey('product', $pages);
        $this->assertSame([], $this->cache);
        $this->assertArrayHasKey('search', $pages);
    }

    public function testPinnedPathWinsAndForeignHostsAreRejected(): void
    {
        $pages = $this->discovery(['cat.html', null], [
            'stacknuts_stackgauge/page_speed/product_path' => '/my-product.html',
            'stacknuts_stackgauge/page_speed/category_path' => 'https://evil.test/x',
            'stacknuts_stackgauge/page_speed/cart_path' => 'https://shop.test/custom-cart',
        ])->discover();

        $this->assertSame('pinned', $pages['product']['source']);
        $this->assertSame('https://shop.test/my-product.html', $pages['product']['url']);
        $this->assertSame('https://shop.test/cat.html', $pages['category']['url']);
        $this->assertSame('https://shop.test/custom-cart', $pages['cart']['url']);
    }

    public function testReturnsNothingWhenTheStoreCannotBeLoaded(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willThrowException(new \RuntimeException('no store'));

        $discovery = new KeyPageDiscovery(
            $this->createMock(ResourceConnection::class),
            $storeManager,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(CacheInterface::class)
        );

        $this->assertSame([], $discovery->discover());
    }

    /**
     * @param list<string|null> $fetchResults answers to each successive path query
     * @param array<string,string> $config admin config by path
     */
    private function discovery(array $fetchResults, array $config = []): KeyPageDiscovery
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->expects($this->atLeastOnce())->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_LINK, true)->willReturn('https://shop.test/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use (&$fetchResults) {
            $this->queries[] = $sql;

            return array_shift($fetchResults) ?? false;
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn (string $path) => $config[$path] ?? null);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->cache[$key] = $data;

            return true;
        });

        return new KeyPageDiscovery($resource, $storeManager, $scope, $cache);
    }
}
