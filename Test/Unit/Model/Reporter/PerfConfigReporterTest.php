<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
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

        $fields = (new PerfConfigReporter($state, $scope, $deployment, new Field(), new Section()))
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

        $fields = (new PerfConfigReporter($state, $scope, $deployment, new Field(), new Section()))
            ->getStatus()['general']->getFields();

        $this->assertSame('varnish', $fields['fpc_application']->getValue());
        $this->assertFalse($fields['fpc_is_built_in']->getValue());
        $this->assertFalse($fields['cache_backend_is_file_based']->getValue());
        $this->assertFalse($fields['session_is_file_based']->getValue());
    }
}
