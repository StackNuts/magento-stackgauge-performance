<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use Magento\Framework\Module\ModuleListInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\PerformanceSectionTrait;

/**
 * Module bloat and known performance-sensitive extensions. The list is deliberately short and
 * limited to modules with a documented tendency to add per-request or per-product cost; a hit
 * is a prompt to profile that module, not a verdict that it is slow on this store.
 */
class ModuleHygieneReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * Magento module name => why it deserves a look.
     */
    private const KNOWN_HEAVY_MODULES = [
        'Amasty_Shopby' => 'Layered navigation can add heavy SQL on large catalogs; use ajax mode and a search engine.',
        'Amasty_Label' => 'Per-product label queries; check caching on category pages with many products.',
        'Amasty_Checkout' => 'Replaces the checkout with a heavy JS bundle; verify JS bundling and checkout load time.',
        'Mirasvit_LayeredNavigation' => 'Can generate many queries on large attribute sets.',
        'Mirasvit_Search' => 'Runs its own search stack alongside Magento search; confirm it is needed and indexed.',
        'Mageplaza_Productslider' => 'Adds queries for every slider instance rendered.',
        'Mageplaza_OnePageCheckout' => 'Loads large checkout JS bundles; profile checkout.',
        'MageWorx_SeoBase' => 'Adds overhead to catalog URL generation on large catalogs.',
        'Wyomind_Datafeed' => 'Large feed generation via cron; schedule off-peak.',
        'Xtento_ProductExport' => 'Large exports can lock tables; schedule carefully.',
        'Firebear_ImportExport' => 'Memory-intensive imports; run from CLI, not the admin.',
    ];

    /**
     * @param ModuleListInterface $moduleList
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the module hygiene reporter.
     */
    public function getName(): string
    {
        return 'module_hygiene';
    }

    /**
     * Human-readable label for the module hygiene reporter block.
     */
    public function getLabel(): string
    {
        return 'Module Hygiene';
    }

    /**
     * One-line summary of what the module hygiene reporter covers.
     */
    public function getDescription(): string
    {
        return 'Enabled module counts and extensions with a known tendency to add performance cost.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports module counts and any enabled known performance-sensitive modules.
     */
    public function getStatus(): array
    {
        $names = $this->moduleList->getNames();
        $thirdParty = array_filter($names, static fn (string $name): bool => !str_starts_with($name, 'Magento_'));

        $rows = [];
        foreach (self::KNOWN_HEAVY_MODULES as $module => $note) {
            if (in_array($module, $names, true)) {
                $rows[] = $this->field->array($module, [
                    'module' => $this->field->varchar('Module', $module),
                    'note' => $this->field->varchar('Why It Matters', $note),
                ]);
            }
        }

        return [
            'summary' => $this->section->facts('summary', 'Summary', $this->getDescription(), [
                'enabled_modules' => $this->field->number('Enabled Modules', count($names)),
                'third_party_modules' => $this->field->number('Third-party Modules', count($thirdParty)),
                'flagged_modules' => $this->field->number(
                    'Known Performance-sensitive Modules',
                    count($rows),
                    $rows !== [] ? Field::SEVERITY_WARNING : null
                ),
            ]),
            'flagged' => $this->section->table('flagged', 'Flagged Modules', $this->getDescription(), $rows),
        ];
    }
}
