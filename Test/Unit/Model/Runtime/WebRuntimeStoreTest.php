<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Runtime;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGaugePerformance\Model\Runtime\WebRuntimeStore;

class WebRuntimeStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sg_perf_store_' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach ((array)glob($this->root . '/*/*') as $file) {
            unlink($file);
        }
        foreach ((array)glob($this->root . '/*', GLOB_ONLYDIR) as $dir) {
            rmdir($dir);
        }
        rmdir($this->root);
    }

    public function testWriteThenReadRoundTripsAndTagsTheHost(): void
    {
        $store = $this->store();
        $store->write(['collected_at' => 1000, 'php_version' => '8.3']);

        $all = $store->readAll();

        $this->assertCount(1, $all);
        $snapshot = reset($all);
        $this->assertSame(gethostname(), $snapshot['host']);
        $this->assertSame('8.3', $snapshot['php_version']);
        $this->assertSame($snapshot, $store->read());
        $this->assertSame([], glob($this->root . '/stacknuts_stackgaugeperformance/*.tmp'));
    }

    public function testEachHostHasItsOwnFileAndReadReturnsTheNewest(): void
    {
        $dir = $this->root . '/stacknuts_stackgaugeperformance';
        mkdir($dir);
        file_put_contents($dir . '/web_runtime_web1.json', json_encode(['host' => 'web1', 'collected_at' => 100]));
        file_put_contents($dir . '/web_runtime_web2.json', json_encode(['host' => 'web2', 'collected_at' => 200]));
        file_put_contents($dir . '/web_runtime_bad.json', 'not json');
        file_put_contents($dir . '/unrelated.json', json_encode(['host' => 'x', 'collected_at' => 999]));

        $store = $this->store();

        $this->assertSame(['web1', 'web2'], array_keys($store->readAll()));
        $this->assertSame('web2', $store->read()['host']);
    }

    public function testLegacySingleFileIsReadOnlyUntilAHostFileExists(): void
    {
        $dir = $this->root . '/stacknuts_stackgaugeperformance';
        mkdir($dir);
        file_put_contents($dir . '/web_runtime.json', json_encode(['collected_at' => 50, 'php_version' => '8.1']));
        $store = $this->store();

        $this->assertSame(['unknown'], array_keys($store->readAll()));
        $this->assertSame('8.1', $store->read()['php_version']);

        $store->write(['collected_at' => 60, 'php_version' => '8.3']);

        $this->assertSame([(string)gethostname()], array_keys($store->readAll()));
    }

    public function testEmptyStoreReadsNothing(): void
    {
        $this->assertSame([], $this->store()->readAll());
        $this->assertNull($this->store()->read());
    }

    public function testIsStaleWhenMissingOrOldAndFreshAfterWrite(): void
    {
        $store = $this->store();
        $this->assertTrue($store->isStale(1800));

        $store->write(['collected_at' => time()]);
        $this->assertFalse($store->isStale(1800));

        $file = glob($this->root . '/stacknuts_stackgaugeperformance/web_runtime_*.json')[0];
        touch($file, time() - 4000);
        clearstatcache();
        $this->assertTrue($store->isStale(1800));
    }

    private function store(): WebRuntimeStore
    {
        $root = $this->root;
        $dir = $this->createMock(WriteInterface::class);
        $dir->method('isExist')->willReturnCallback(static fn (string $p): bool => file_exists($root . '/' . $p));
        $dir->method('stat')->willReturnCallback(static function (string $p) use ($root): array {
            clearstatcache();

            return ['mtime' => filemtime($root . '/' . $p)];
        });
        $dir->method('create')->willReturnCallback(static function (string $p) use ($root): bool {
            return is_dir($root . '/' . $p) || mkdir($root . '/' . $p, 0777, true);
        });
        $dir->method('writeFile')->willReturnCallback(
            static fn (string $p, string $c): int => (int)file_put_contents($root . '/' . $p, $c)
        );
        $dir->method('renameFile')->willReturnCallback(
            static fn (string $from, string $to): bool => rename($root . '/' . $from, $root . '/' . $to)
        );
        $dir->method('read')->willReturnCallback(static fn (string $p): array => array_map(
            static fn (string $f): string => $p . '/' . basename($f),
            (array)glob($root . '/' . $p . '/*')
        ));
        $dir->method('readFile')->willReturnCallback(
            static fn (string $p): string => (string)file_get_contents($root . '/' . $p)
        );
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($dir);

        return new WebRuntimeStore($filesystem);
    }
}
