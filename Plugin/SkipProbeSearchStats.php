<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Search\Model\Query;
use StackNuts\StackGaugePerformance\Model\Probe\PageTimer;

/**
 * Keeps the page_speed reporter's search probe (see Model\Probe\KeyPageDiscovery) out of the
 * store's search terms report - otherwise its fixed term would gain a hit every run and skew the
 * merchant's "popular searches" data. Recognises the probe by PageTimer::PROBE_HEADER. Anyone
 * can send that header, but all it does is leave their own search uncounted.
 */
class SkipProbeSearchStats
{
    /**
     * @param RequestInterface $request
     */
    public function __construct(private readonly RequestInterface $request)
    {
    }

    /**
     * Skips the popularity increment for a probe request.
     *
     * @param Query $subject
     * @param callable $proceed
     * @return Query
     */
    public function aroundSaveIncrementalPopularity(Query $subject, callable $proceed)
    {
        return $this->isProbe() ? $subject : $proceed();
    }

    /**
     * Skips the result-count update for a probe request.
     *
     * @param Query $subject
     * @param callable $proceed
     * @param int $numResults
     * @return Query
     */
    public function aroundSaveNumResults(Query $subject, callable $proceed, $numResults)
    {
        return $this->isProbe() ? $subject : $proceed($numResults);
    }

    /**
     * Whether the current request came from PageTimer.
     */
    private function isProbe(): bool
    {
        return method_exists($this->request, 'getHeader')
            && (string)$this->request->getHeader(PageTimer::PROBE_HEADER) !== '';
    }
}
