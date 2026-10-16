<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Probe;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Picks representative storefront URLs to time: the category with the most products (the heaviest
 * listing page), the lowest-id product with a canonical rewrite, plus fixed search and cart paths.
 * Uses the default store view's url_rewrite rows so no catalog collections are loaded; the picks are
 * restricted to enabled/visible products and active categories (falling back to the unrestricted pick if
 * that query can't run, e.g. on a staging schema) and cached for a day so the page being timed stays the
 * same between runs. Each type can
 * be pinned in admin config (a path on this store, or a full URL on the same host); a pinned type
 * is reported with source "pinned", an auto-picked one with "auto".
 */
class KeyPageDiscovery
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_PINNED = 'pinned';
    public const CONFIG_PREFIX = 'stacknuts_stackgauge/page_speed/';
    private const SEARCH_TERM = 'product';
    private const CACHE_KEY = 'stacknuts_stackgaugeperformance_keypages_';
    private const CACHE_TTL_SECONDS = 86400;
    private const VISIBLE_IN_CATALOG_OR_BOTH = '2, 3, 4';

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CacheInterface $cache
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
            /** @var \Magento\Store\Model\Store $store */
            // Secure link URL: the CLI would otherwise give the unsecure one, and every probe would time a 301.
            $base = rtrim((string)$store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true), '/') . '/';
            $storeId = (int)$store->getId();
        } catch (Throwable) {
            return [];
        }

        $defaults = [
            'category' => fn (): ?string => $this->cachedPath('category', $storeId),
            'product' => fn (): ?string => $this->cachedPath('product', $storeId),
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

    /**
     * The auto-picked path for a type, remembered for a day. A miss is never cached, so it is retried next run.
     *
     * @param string $type
     * @param int $storeId
     */
    private function cachedPath(string $type, int $storeId): ?string
    {
        $key = self::CACHE_KEY . $type . '_' . $storeId;
        try {
            $cached = $this->cache->load($key);
        } catch (Throwable) {
            $cached = false;
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $queries = $type === 'category'
            ? [$this->categoryQuery(true), $this->categoryQuery(false)]
            : [$this->productQuery(true), $this->productQuery(false)];
        $path = null;
        foreach ($queries as $sql) {
            $path = $this->firstPath($sql, $storeId);
            if ($path !== null) {
                break;
            }
        }

        if ($path !== null) {
            try {
                $this->cache->save($path, $key, [], self::CACHE_TTL_SECONDS);
            } catch (Throwable) {
                // A cache write failure only means the pick is recomputed next run.
            }
        }

        return $path;
    }

    /**
     * SQL fragment joining an integer EAV attribute (default scope) that must equal/lie in a set.
     *
     * @param string $alias
     * @param string $entityTable
     * @param string $entityTypeCode
     * @param string $attributeCode
     * @param string $condition
     * @param string $idColumn
     */
    private function eavFilter(
        string $alias,
        string $entityTable,
        string $entityTypeCode,
        string $attributeCode,
        string $condition,
        string $idColumn
    ): string {
        $values = $this->resource->getTableName($entityTable . '_int');
        $attribute = $this->resource->getTableName('eav_attribute');
        $type = $this->resource->getTableName('eav_entity_type');

        return " JOIN {$values} {$alias} ON {$alias}.{$idColumn} = ur.entity_id AND {$alias}.store_id = 0 "
            . "AND {$alias}.value {$condition} AND {$alias}.attribute_id = (SELECT a.attribute_id FROM {$attribute} a "
            . "JOIN {$type} t ON t.entity_type_id = a.entity_type_id WHERE a.attribute_code = '{$attributeCode}' "
            . "AND t.entity_type_code = '{$entityTypeCode}')";
    }

    private function categoryQuery(bool $activeOnly): string
    {
        $rewrite = $this->resource->getTableName('url_rewrite');
        $link = $this->resource->getTableName('catalog_category_product');
        $active = $activeOnly
            ? $this->eavFilter('act', 'catalog_category_entity', 'catalog_category', 'is_active', '= 1', 'entity_id')
            : '';

        return "SELECT ur.request_path FROM {$rewrite} ur JOIN {$link} ccp ON ccp.category_id = ur.entity_id{$active} "
            . "WHERE ur.entity_type = 'category' AND ur.redirect_type = 0 AND ur.store_id = :store_id "
            . 'GROUP BY ur.entity_id, ur.request_path ORDER BY COUNT(*) DESC, ur.entity_id LIMIT 1';
    }

    private function productQuery(bool $enabledOnly): string
    {
        $rewrite = $this->resource->getTableName('url_rewrite');
        $filters = $enabledOnly
            ? $this->eavFilter('st', 'catalog_product_entity', 'catalog_product', 'status', '= 1', 'entity_id')
                . $this->eavFilter(
                    'vis',
                    'catalog_product_entity',
                    'catalog_product',
                    'visibility',
                    'IN (' . self::VISIBLE_IN_CATALOG_OR_BOTH . ')',
                    'entity_id'
                )
            : '';

        return "SELECT ur.request_path FROM {$rewrite} ur{$filters} WHERE ur.entity_type = 'product' "
            . 'AND ur.redirect_type = 0 AND ur.metadata IS NULL AND ur.store_id = :store_id '
            . 'ORDER BY ur.entity_id LIMIT 1';
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
