<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\Search\Query\SearchQuery;
use SilverStripe\ORM\DataList;

/**
 * Intrinsic category facet. Works on SilverShop core alone — matching products in a category and its descendants via
 * core's `AllChildCategoryIDs()` + the `ParentID`-OR-`ProductCategories` filter — and transparently uses
 * silvershop/category-index's flat `EffectiveCategories` index (one JOIN) when that module is installed, which is
 * faster and lets facet counts run without the per-level tree walk. Facet values are category URL segments.
 *
 * Ships in the search core (not in category-index) so category faceting is available to every shop; category-index
 * is a drop-in performance upgrade, not a prerequisite.
 */
class CategoryFacet implements SearchFacet
{
    public function getName(): string
    {
        return 'category';
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Category');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function apply(DataList $products, array $selected): DataList
    {
        if (!$selected) {
            return $products;
        }

        $categories = ProductCategory::get()->filter('URLSegment', $selected)->toArray();
        if (!$categories) {
            // A selected segment that matches no category should yield no products, not be ignored.
            return $products->filter('ID', [-1]);
        }

        return $this->applyCategories($products, $categories);
    }

    public function getOptions(DataList $context, SearchQuery $query): array
    {
        $selected = array_map('strval', $query->selected($this->getName()));

        // With the flat EffectiveCategories index, count every category in one pass over the context instead of a
        // COUNT query per category (N+1). Without the index, fall back to the per-category count (the core-native
        // recursive membership can't be collapsed as cheaply).
        $counts = $this->hasIndex() ? $this->countProductsByCategory($context) : null;

        $options = [];
        foreach (ProductCategory::get() as $category) {
            $value = (string) $category->URLSegment;
            if ($value === '') {
                continue;
            }
            $count = $counts !== null
                ? (int) ($counts[(int) $category->ID] ?? 0)
                : (int) $this->applyCategories($context, [$category])->count();
            $active = in_array($value, $selected, true);
            if ($count === 0 && !$active) {
                continue;
            }
            $options[] = [
                'value' => $value,
                'label' => $category->Title,
                'count' => $count,
                'active' => $active,
            ];
        }

        return $options;
    }

    /**
     * Distinct context-product count per effective category, built in a couple of queries: the context's products
     * with their EffectiveCategories eager-loaded, tallied per category in PHP. Each product appears once per
     * category in the flat index, so a simple increment is the distinct-product count. Index-only (see getOptions).
     *
     * @return array<int, int> categoryID => distinct product count
     */
    private function countProductsByCategory(DataList $context): array
    {
        $counts = [];
        foreach ($context->eagerLoad('EffectiveCategories') as $product) {
            foreach ($product->EffectiveCategories() as $category) {
                $id = (int) $category->ID;
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Narrow $products to those in any of $categories (descendants included).
     *
     * @param array<int, ProductCategory> $categories
     */
    private function applyCategories(DataList $products, array $categories): DataList
    {
        if ($this->hasIndex()) {
            // EffectiveCategories already carries every ancestor, so a direct match covers descendants.
            // Resolve the matching product IDs in a STANDALONE query, then narrow $products by primary key.
            // Filtering EffectiveCategories.ID directly on $products would collide with any EffectiveCategories
            // JOIN the base list already carries — a category page's own listing adds one — because Silverstripe
            // reuses the single join and ANDs both category conditions onto one row, matching nothing.
            $ids = array_map(static fn (ProductCategory $c): int => (int) $c->ID, $categories);
            $matchIds = Product::get()->filter('EffectiveCategories.ID', $ids)->columnUnique('ID');

            return $products->filter('ID', $matchIds ?: [-1]);
        }

        // Core-native: expand each category to itself + descendants, then match primary or secondary membership.
        $ids = [];
        foreach ($categories as $category) {
            $ids[] = (int) $category->ID;
            foreach ($category->AllChildCategoryIDs() as $childID) {
                $ids[] = (int) $childID;
            }
        }
        $ids = array_values(array_unique($ids));

        return $products->filterAny([
            'ParentID' => $ids,
            'ProductCategories.ID' => $ids,
        ]);
    }

    /**
     * Whether silvershop/category-index's flat EffectiveCategories relation is present on Product.
     */
    private function hasIndex(): bool
    {
        return array_key_exists('EffectiveCategories', (array) Product::config()->get('many_many'));
    }
}
