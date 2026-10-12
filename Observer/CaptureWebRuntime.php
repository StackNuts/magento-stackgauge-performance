<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use StackNuts\StackGaugePerformance\Model\Runtime\WebRuntimeCollector;
use StackNuts\StackGaugePerformance\Model\Runtime\WebRuntimeStore;
use Throwable;

/**
 * Refreshes the web-runtime snapshot from a small random sample of storefront requests. For
 * the other requests the cost is one random_int() call; a sampled request pays one stat() and,
 * at most once per REFRESH_SECONDS, one snapshot write. Never throws - this must not be able
 * to break a customer's page load.
 */
class CaptureWebRuntime implements ObserverInterface
{
    private const SAMPLE_ONE_IN = 25;
    private const REFRESH_SECONDS = 1800;

    /**
     * @param WebRuntimeCollector $collector
     * @param WebRuntimeStore $store
     */
    public function __construct(
        private readonly WebRuntimeCollector $collector,
        private readonly WebRuntimeStore $store
    ) {
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        try {
            if (random_int(1, self::SAMPLE_ONE_IN) !== 1 || !$this->store->isStale(self::REFRESH_SECONDS)) {
                return;
            }

            $this->store->write($this->collector->collect());
        } catch (Throwable) {
            // Deliberately silent - see class docblock.
        }
    }
}
