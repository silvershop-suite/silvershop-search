<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverShop\Page\Product;
use SilverShop\Search\Query\SearchQuery;
use SilverStripe\ORM\DataList;

/**
 * An "In stock" availability toggle, active only when silvershop/stock is installed (detected by the
 * `hasAvailableStock()` method that module adds to the buyable). Availability is read from stock's own
 * authoritative `hasAvailableStock()` rather than reimplemented in SQL — stock's rules (per-warehouse levels,
 * unlimited flags, variations, cart-pending, digital-product overrides) are intentionally not duplicated here.
 *
 * Because that check is per-product (not a single query), this suits typical catalogues; very large catalogues that
 * need availability filtering at scale should use an engine backend. No hard dependency on silvershop/stock — the
 * facet simply reports itself unavailable (and is skipped) when the module is absent.
 */
class StockFacet implements SearchFacet
{
    private const IN_STOCK = 'in';

    public function getName(): string
    {
        return 'stock';
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Availability');
    }

    public function isAvailable(): bool
    {
        return Product::singleton()->hasMethod('hasAvailableStock');
    }

    public function apply(DataList $products, array $selected): DataList
    {
        if (!in_array(self::IN_STOCK, array_map('strval', $selected), true)) {
            return $products;
        }

        return $products->filter('ID', $this->inStockIds($products) ?: [-1]);
    }

    public function getOptions(DataList $context, SearchQuery $query): array
    {
        $active = in_array(self::IN_STOCK, array_map('strval', $query->selected($this->getName())), true);
        $count = count($this->inStockIds($context));

        if ($count === 0 && !$active) {
            return [];
        }

        return [[
            'value' => self::IN_STOCK,
            'label' => _t(self::class . '.IN_STOCK', 'In stock'),
            'count' => $count,
            'active' => $active,
        ]];
    }

    /**
     * IDs of the products in $list that stock reports as available.
     *
     * @return array<int, int>
     */
    private function inStockIds(DataList $list): array
    {
        $this->primeStock($list);

        $ids = [];
        foreach ($list as $product) {
            if ($product->hasMethod('hasAvailableStock') && $product->hasAvailableStock()) {
                $ids[] = (int) $product->ID;
            }
        }

        return $ids;
    }

    /**
     * Prime silvershop/stock's request caches for the whole list up front, so the per-product hasAvailableStock()
     * checks below read from cache instead of each firing its own warehouse-stock / cart-sum / variation queries
     * (the availability check is per-product by design, so counting it over a listing is otherwise an N+1). No-op
     * when the stock module is absent or predates the batch-prime API.
     */
    private function primeStock(DataList $list): void
    {
        $ext = 'SilverShop\\Stock\\Extensions\\ProductStockExtension';
        if (!class_exists($ext) || !method_exists($ext, 'primeStockCache')) {
            return;
        }

        $map = $list->map('ID', 'ClassName')->toArray();
        if (!$map) {
            return;
        }

        $ext::primeStockCache($map);
        $ext::primeCartReservationsCache($map);
        if (method_exists($ext, 'primeVariationsCache')) {
            $ext::primeVariationsCache($map);
        }
    }
}
