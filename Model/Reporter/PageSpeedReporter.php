<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Reporter;

use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
use StackNuts\StackGauge\Model\Reporter\Concern\PerformanceSectionTrait;
use StackNuts\StackGaugePerformance\Model\Probe\KeyPageDiscovery;
use StackNuts\StackGaugePerformance\Model\Probe\PageTimer;

/**
 * Time to first byte for the page types that matter - category, product, search and cart - timed
 * from the server itself through the store's public URL, so the request passes through whatever
 * sits in front of Magento (CDN, Varnish) and the figure includes it. The homepage is already timed by the uptime reporter. Each page is requested once, so a
 * cold full-page-cache entry shows up as a slow "miss" rather than being hidden by a warm-up hit.
 */
class PageSpeedReporter implements ReporterInterface, DeclaresSectionInterface, MetricCatalogInterface, DeclaresSlotInterface
{
    use PerformanceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_PREFIX = 'page_speed.';
    private const METRIC_LABELS = [
        'category' => 'Category',
        'product' => 'Product',
        'search' => 'Search',
        'cart' => 'Cart',
    ];

    /**
     * @param KeyPageDiscovery $discovery
     * @param PageTimer $timer
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly KeyPageDiscovery $discovery,
        private readonly PageTimer $timer,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the page speed reporter.
     */
    public function getName(): string
    {
        return 'page_speed';
    }

    /**
     * Human-readable label for the page speed reporter block.
     */
    public function getLabel(): string
    {
        return 'Page Speed';
    }

    /**
     * One-line summary of what the page speed reporter covers.
     */
    public function getDescription(): string
    {
        return 'Server-side time to first byte for category, product, search and cart pages, with cache status.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Times each key page once and reports TTFB, status and cache verdict.
     */
    public function getStatus(): array
    {
        $summary = [];
        $rows = [];

        foreach ($this->discovery->discover() as $type => $page) {
            $url = $page['url'];
            $result = $this->timer->time($url);
            // Only a successful page says anything about speed: a 301, a WAF challenge or a 404 is quick for other reasons.
            $responded = $result['status'] >= 200 && $result['status'] < 300;

            if ($responded && isset(self::METRIC_LABELS[$type])) {
                $summary[$type . '_ttfb_ms'] = $this->field->trackableNumber(
                    self::METRIC_LABELS[$type] . ' TTFB',
                    $result['ttfb_ms'],
                    self::METRIC_PREFIX . $type . '_ttfb_ms',
                    MetricDefinition::AGGREGATION_AVG
                );
            }

            $rows[] = $this->field->array($type, [
                'page' => $this->field->varchar('Page', $type),
                'url' => $this->field->varchar('URL', $url),
                'source' => $this->field->varchar('Source', $page['source']),
                'http_status' => $this->field->number('HTTP Status', $result['status']),
                'ttfb_ms' => $this->field->number('TTFB', $result['ttfb_ms'], unit: Unit::MILLISECONDS),
                'total_ms' => $this->field->number('Total', $result['total_ms'], unit: Unit::MILLISECONDS),
                'cache' => $this->field->varchar('Cache', $result['cache']),
            ]);
        }

        $summary['pages_timed'] = $this->field->number('Pages Timed', count($rows));

        return [
            'summary' => $this->section->facts('summary', 'Summary', $this->getDescription(), $summary),
            'pages' => $this->section->table('pages', 'Pages', $this->getDescription(), $rows),
        ];
    }

    /**
     * Alertable metrics: TTFB per page type.
     */
    public function getTrackableMetrics(): array
    {
        $metrics = [];
        foreach (self::METRIC_LABELS as $type => $label) {
            $metrics[] = new MetricDefinition(
                self::METRIC_PREFIX . $type . '_ttfb_ms',
                'Page Speed: ' . $label . ' TTFB',
                MetricDefinition::AGGREGATION_AVG,
                MetricDefinition::OPERATOR_GT,
                $type === 'cart' ? 1500 : 1000,
                180,
                null,
                description: 'The ' . strtolower($label) . ' page took {value} to start responding.',
                impact: 'Slow server response delays every visitor on this page type before anything renders.',
                criticalThreshold: $type === 'cart' ? 4000 : 3000,
                unit: Unit::MILLISECONDS
            );
        }

        return $metrics;
    }

    /**
     * Run 2 within this reporter's cadence tier.
     */
    public function getSlot(): int
    {
        return 2;
    }
}
