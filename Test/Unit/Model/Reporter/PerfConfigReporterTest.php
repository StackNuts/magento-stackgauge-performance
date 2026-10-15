<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGaugePerformance\Model\Reporter\PerfConfigReporter;

class PerfConfigReporterTest extends TestCase
{
    public function testFlagsBuiltInCacheFileBackendsAndDeveloperMode(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn('developer');
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnMap([
            ['system/full_page_cache/caching_application', 'default', null, 1],
            ['system/full_page_cache/ttl', 'default', null, 3600],
        ]);
        $scope->method('isSetFlag')->willReturn(false);
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnMap([
            ['cache/frontend/default/backend', '', ''],
            ['session/save', 'files', 'files'],
        ]);

        $fields = (new PerfConfigReporter($state, $scope, $deployment, $this->registry(false), new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertSame('developer', $fields['deploy_mode']->getValue());
        $this->assertSame('built-in', $fields['fpc_application']->getValue());
        $this->assertTrue($fields['fpc_is_built_in']->getValue());
        $this->assertTrue($fields['cache_backend_is_file_based']->getValue());
        $this->assertTrue($fields['session_is_file_based']->getValue());
        $this->assertSame(3600, $fields['fpc_ttl_seconds']->getValue());
    }

    public function testRedisAndVarnishAreNotFlagged(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn('production');
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnMap([
            ['system/full_page_cache/caching_application', 'default', null, 2],
            ['system/full_page_cache/ttl', 'default', null, 86400],
        ]);
        $scope->method('isSetFlag')->willReturn(false);
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnMap([
            ['cache/frontend/default/backend', '', '\\Magento\\Framework\\Cache\\Backend\\Redis'],
            ['session/save', 'files', 'redis'],
        ]);

        $fields = (new PerfConfigReporter($state, $scope, $deployment, $this->registry(false), new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertSame('varnish', $fields['fpc_application']->getValue());
        $this->assertFalse($fields['fpc_is_built_in']->getValue());
        $this->assertFalse($fields['cache_backend_is_file_based']->getValue());
        $this->assertFalse($fields['session_is_file_based']->getValue());
    }

    public function testReportsCssAndJsMinifyFlags(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn('production');
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn(null);
        $scope->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => $path === 'dev/css/minify_files'
        );
        $deployment = $this->createMock(DeploymentConfig::class);

        $fields = (new PerfConfigReporter($state, $scope, $deployment, $this->registry(false), new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertTrue($fields['css_minify_enabled']->getValue());
        $this->assertFalse($fields['js_minify_enabled']->getValue());
    }

    public function testFlagsDebugLoggingAndProfilerOnlyWhenActuallyOn(): void
    {
        $fields = $this->statusWith([
            'dev/debug/debug_logging' => 1,
            'db/connection' => ['default' => ['profiler' => '1'], 'indexer' => ['profiler' => '0']],
        ], 'production')->getFields();

        $this->assertTrue($fields['debug_logging_in_production']->getValue());
        $this->assertTrue($fields['db_profiler_enabled']->getValue());

        $fields = $this->statusWith([
            'dev/debug/debug_logging' => 1,
            'db/connection' => ['default' => ['host' => 'x']],
        ], 'developer')->getFields();

        $this->assertFalse($fields['debug_logging_in_production']->getValue());
        $this->assertFalse($fields['db_profiler_enabled']->getValue());
    }

    public function testDetectsSharedRedisDatabaseWithoutLeakingEndpoints(): void
    {
        $redis = static fn (string $db): array => ['backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
            'backend_options' => ['server' => 'redis', 'port' => '6379', 'database' => $db, 'password' => 's3cret']];
        $config = [
            'cache/frontend' => ['default' => $redis('0'), 'page_cache' => $redis('1')],
            'session/save' => 'redis',
            'session/redis' => ['host' => 'redis', 'port' => '6379', 'database' => '2', 'disable_locking' => '0'],
        ];

        $this->assertFalse($this->statusWith($config)->getFields()['shared_redis_database']->getValue());

        $config['session/redis']['database'] = '1';
        $section = $this->statusWith($config);

        $this->assertTrue($section->getFields()['shared_redis_database']->getValue());
        $this->assertStringNotContainsString('s3cret', json_encode($section));
    }

    public function testReportsSessionTuningAndL2Cache(): void
    {
        $fields = $this->statusWith([
            'cache/frontend/default/backend' => '\\Magento\\Framework\\Cache\\Backend\\RemoteSynchronizedCache',
            'cache/frontend' => ['default' => [
                'backend' => '\\Magento\\Framework\\Cache\\Backend\\RemoteSynchronizedCache',
                'backend_options' => ['remote_backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
                    'remote_backend_options' => ['server' => 'redis', 'database' => '0']],
            ]],
            'session/save' => 'redis',
            'session/redis' => ['disable_locking' => '1', 'compression_library' => 'gzip', 'max_concurrency' => '8',
                'break_after_frontend' => '3', 'host' => 'redis', 'database' => '0'],
        ])->getFields();

        $this->assertTrue($fields['l2_cache_enabled']->getValue());
        $this->assertTrue($fields['session_locking_disabled']->getValue());
        $this->assertSame('gzip', $fields['session_compression_lib']->getValue());
        $this->assertSame(8, $fields['session_max_concurrency']->getValue());
        $this->assertSame(3, $fields['session_break_after_frontend']->getValue());
        $this->assertTrue($fields['shared_redis_database']->getValue());
    }

    public function testCustomerGridSaveModeFlagAndMissingIndexer(): void
    {
        $this->assertTrue($this->statusWith([], 'production', true)->getFields()['customer_grid_not_update_on_save']->getValue());
        $this->assertFalse($this->statusWith([], 'production', false)->getFields()['customer_grid_not_update_on_save']->getValue());
        $this->assertArrayNotHasKey(
            'customer_grid_not_update_on_save',
            $this->statusWith([], 'production', null)->getFields()
        );
    }

    /**
     * @param array<string, mixed> $config deployment config values by path
     */
    private function statusWith(array $config, string $mode = 'production', ?bool $scheduled = false)
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn($mode);
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(false);
        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(
            static fn (string $path, $default = null) => $config[$path] ?? $default
        );

        return (new PerfConfigReporter($state, $scope, $deployment, $this->registry($scheduled), new Field(), new Section()))
            ->getStatus()['general'];
    }

    private function registry(?bool $scheduled): IndexerRegistry
    {
        $registry = $this->createMock(IndexerRegistry::class);
        if ($scheduled === null) {
            $registry->method('get')->willThrowException(new \InvalidArgumentException('missing'));

            return $registry;
        }
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn($scheduled);
        $registry->method('get')->willReturn($indexer);

        return $registry;
    }
}
