<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Field\TrackableNumberField;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Redis\RedisInstance;
use StackNuts\StackGauge\Model\Redis\RedisInstanceResolver;
use StackNuts\StackGaugePerformance\Model\Reporter\RedisPerformanceReporter;

class RedisPerformanceReporterTest extends TestCase
{
    private const MB = 1048576;

    public function testReportsZeroInstancesWhenNoRedisBackendsConfigured(): void
    {
        $fields = $this->reporter([], [])->getStatus()['summary']->getFields();

        $this->assertSame(0, $fields['instances_checked']->getValue());
        $this->assertSame(0, $fields['evicted_keys']->getValue());
        $this->assertArrayNotHasKey('memory_used_pct', $fields);
        $this->assertArrayNotHasKey('hit_rate', $fields);
    }

    public function testDeclaresThreeMetrics(): void
    {
        $this->assertCount(3, $this->reporter([], [])->getTrackableMetrics());
    }

    public function testFindsMagentoClassCacheBackendAndSumsAcrossInstances(): void
    {
        $status = $this->reporter($this->config(), [
            'cache-host' => ['used_memory' => 50 * self::MB, 'maxmemory' => 100 * self::MB, 'keyspace_hits' => 90,
                'keyspace_misses' => 10, 'evicted_keys' => 4, 'maxmemory_policy' => 'allkeys-lru'],
            'sess-host' => ['used_memory' => 95 * self::MB, 'maxmemory' => 100 * self::MB, 'keyspace_hits' => 1,
                'keyspace_misses' => 99, 'evicted_keys' => 2],
        ])->getStatus();
        $summary = $status['summary']->getFields();

        $this->assertSame(2, $summary['instances_checked']->getValue());
        $this->assertSame(6, $summary['evicted_keys']->getValue());
        $this->assertInstanceOf(TrackableNumberField::class, $summary['evicted_keys']);
        $this->assertSame(95.0, $summary['memory_used_pct']->getValue());
        // The session instance's 1% hit rate must not drag the cache hit rate down.
        $this->assertSame(90.0, $summary['hit_rate']->getValue());
        $this->assertCount(2, $status['instances']->getRows());
    }

    public function testUnreachableInstanceStopsTheEvictionMetricFromBeingRecorded(): void
    {
        $status = $this->reporter($this->config(), [
            'cache-host' => ['used_memory' => 1, 'maxmemory' => 0, 'keyspace_hits' => 1, 'keyspace_misses' => 1, 'evicted_keys' => 9],
            'sess-host' => null,
        ])->getStatus();
        $summary = $status['summary']->getFields();

        $this->assertSame(1, $summary['instances_unreachable']->getValue());
        $this->assertSame(1, $summary['instances_checked']->getValue());
        $this->assertNotInstanceOf(TrackableNumberField::class, $summary['evicted_keys']);
    }

    public function testSessionAndCacheOnTheSameServerAreCheckedOnce(): void
    {
        $config = ['cache' => ['frontend' => ['default' => ['backend' => 'redis',
            'backend_options' => ['server' => 'one', 'database' => '0']]]],
            'session' => ['save' => 'redis', 'redis' => ['host' => 'one', 'database' => '2']]];

        $status = $this->reporter($config, ['one' => ['evicted_keys' => 1, 'keyspace_hits' => 1]])->getStatus();

        $this->assertSame(1, $status['summary']->getFields()['instances_checked']->getValue());
        $this->assertSame('cache_default+session', $status['instances']->getRows()[0]->getValue()['name']->getValue());
    }

    /**
     * @return array<string,mixed>
     */
    private function config(): array
    {
        return ['cache' => ['frontend' => ['default' => [
            'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
            'backend_options' => ['server' => 'cache-host', 'port' => '6379', 'database' => '0'],
        ]]], 'session' => ['save' => 'redis', 'redis' => ['host' => 'sess-host', 'port' => '6379']]];
    }

    /**
     * @param array<string,mixed> $config env.php values
     * @param array<string, array<string,mixed>|null> $infoByHost canned INFO by host, null = unreachable
     */
    private function reporter(array $config, array $infoByHost): RedisPerformanceReporter
    {
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(static function (string $path, $default = null) use ($config) {
            $value = $config;
            foreach (explode('/', $path) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return $default;
                }
                $value = $value[$part];
            }

            return $value;
        });

        return new class (new RedisInstanceResolver($deployment), new Field(), new Section(), $infoByHost)
            extends RedisPerformanceReporter {
            /**
             * @param array<string, array<string,mixed>|null> $infoByHost
             */
            public function __construct(
                RedisInstanceResolver $resolver,
                Field $field,
                Section $section,
                private readonly array $infoByHost
            ) {
                parent::__construct($resolver, $field, $section);
            }

            protected function info(RedisInstance $instance): ?array
            {
                return $this->infoByHost[$instance->host] ?? null;
            }
        };
    }
}
