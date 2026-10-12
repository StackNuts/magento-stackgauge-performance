<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Probe;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Picks representative storefront URLs to time: the category with the most products (the heaviest
 * listing page), the lowest-id product with a canonical rewrite, plus fixed search and cart paths.
 * Uses the default store view's url_rewrite rows so no catalog collections are loaded. Each type can
 * be pinned in admin config (a path on this store, or a full URL on the same host); a pinned type
 * is reported with source "pinned", an auto-picked one with "auto".
 */
class KeyPageDiscovery
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_PINNED = 'pinned';
    public const CONFIG_PREFIX = 'stacknuts_stackgauge/page_speed/';
    private const SEARCH_TERM = 'product';

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Page type => url and source. A type is omitted when it cannot be determined; empty when
     * the store has no usable base URL.
     *
     * @return array<string, array{url: string, source: string}>
     */
    public function discover(): array
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            $base = rtrim((string)$store->getBaseUrl(), '/') . '/';
            $storeId = (int)$store->getId();
        } catch (Throwable) {
            return [];
        }

        $defaults = [
            'category' => fn (): ?string => $this->firstPath($this->categoryQuery(), $storeId),
            'product' => fn (): ?string => $this->firstPath($this->productQuery(), $storeId),
            'search' => static fn (): string => 'catalogsearch/result/?q=' . self::SEARCH_TERM,
            'cart' => static fn (): string => 'checkout/cart/',
        ];

        $pages = [];
        foreach ($defaults as $type => $auto) {
            $pinned = $this->pinnedUrl($type, $base);
            if ($pinned !== null) {
                $pages[$type] = ['url' => $pinned, 'source' => self::SOURCE_PINNED];
                continue;
            }
            $path = $auto();
            if ($path !== null) {
                $pages[$type] = ['url' => $base . $path, 'source' => self::SOURCE_AUTO];
            }
        }

        return $pages;
    }

    /**
     * Admin override for a page type: a relative path, or a full URL that must be on this store's
     * host (so a typo can't turn the probe into a request to somewhere else).
     */
    private function pinnedUrl(string $type, string $base): ?string
    {
        $value = trim((string)$this->scopeConfig->getValue(self::CONFIG_PREFIX . $type . '_path'));
        if ($value === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            return parse_url($value, PHP_URL_HOST) === parse_url($base, PHP_URL_HOST) ? $value : null;
        }

        return $base . ltrim($value, '/');
    }

    private function categoryQuery(): string
    {
        $rewrite = $this->resource->getTableName('url_rewrite');
        $link = $this->resource->getTableName('catalog_category_product');

        return "SELECT ur.request_path FROM {$rewrite} ur JOIN {$link} ccp ON ccp.category_id = ur.entity_id "
            . "WHERE ur.entity_type = 'category' AND ur.redirect_type = 0 AND ur.store_id = :store_id "
            . 'GROUP BY ur.entity_id, ur.request_path ORDER BY COUNT(*) DESC LIMIT 1';
    }

    private function productQuery(): string
    {
        $rewrite = $this->resource->getTableName('url_rewrite');

        return "SELECT request_path FROM {$rewrite} WHERE entity_type = 'product' AND redirect_type = 0 "
            . 'AND metadata IS NULL AND store_id = :store_id ORDER BY entity_id LIMIT 1';
    }

    private function firstPath(string $sql, int $storeId): ?string
    {
        try {
            $path = $this->resource->getConnection()->fetchOne($sql, ['store_id' => $storeId]);
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? ltrim($path, '/') : null;
    }
}
