<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Probe;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Picks representative storefront URLs to time: the category with the most products (the heaviest
 * listing page), the lowest-id product with a canonical rewrite, plus fixed search and cart paths.
 * Uses the default store view's url_rewrite rows so no catalog collections are loaded.
 */
class KeyPageDiscovery
{
    private const SEARCH_TERM = 'product';

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Page type => absolute URL. A type is omitted when it cannot be determined; empty when
     * the store has no usable base URL.
     *
     * @return array<string, string>
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

        $pages = [];
        $category = $this->firstPath($this->categoryQuery(), $storeId);
        if ($category !== null) {
            $pages['category'] = $base . $category;
        }
        $product = $this->firstPath($this->productQuery(), $storeId);
        if ($product !== null) {
            $pages['product'] = $base . $product;
        }
        $pages['search'] = $base . 'catalogsearch/result/?q=' . self::SEARCH_TERM;
        $pages['cart'] = $base . 'checkout/cart/';

        return $pages;
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
