<?php
declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Test\Unit\Model\Reporter;

use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGaugePerformance\Model\Reporter\ModuleHygieneReporter;

class ModuleHygieneReporterTest extends TestCase
{
    public function testCountsModulesAndFlagsKnownHeavyOnes(): void
    {
        $list = $this->createMock(ModuleListInterface::class);
        $list->method('getNames')->willReturn(['Magento_Catalog', 'Magento_Sales', 'Amasty_Shopby', 'Acme_Custom']);

        $status = (new ModuleHygieneReporter($list, new Field(), new Section()))->getStatus();
        $fields = $status['summary']->getFields();

        $this->assertSame(4, $fields['enabled_modules']->getValue());
        $this->assertSame(2, $fields['third_party_modules']->getValue());
        $this->assertSame(1, $fields['flagged_modules']->getValue());
    }

    public function testNoFlagsWhenNoKnownModulesPresent(): void
    {
        $list = $this->createMock(ModuleListInterface::class);
        $list->method('getNames')->willReturn(['Magento_Catalog']);

        $fields = (new ModuleHygieneReporter($list, new Field(), new Section()))
            ->getStatus()['summary']->getFields();

        $this->assertSame(0, $fields['flagged_modules']->getValue());
    }
}
