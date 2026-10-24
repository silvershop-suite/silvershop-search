<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverShop\Search\Query\SearchQuery;
use SilverStripe\ORM\DataList;

/**
 * Applies the registered facets to any product list and computes each facet's counted options. Shared by the search
 * backend and by the on-listing-page faceted navigation (category / brand pages), so all surfaces narrow and count
 * the same way. Each facet's options are counted against the base with every OTHER facet's selection applied, so a
 * facet keeps showing its alternatives after you pick one of its values.
 */
class FacetApplier
{
    /**
     * @return array{products: DataList, facets: array<int, array<string, mixed>>}
     */
    public static function apply(DataList $base, SearchQuery $query): array
    {
        // Fresh sidebar build: drop the attribute dimensions' shared value-count cache so this build's counts reflect
        // the current context/catalogue, never a prior request's.
        AttributeTypeFacet::flushValueCountCache();

        $facets = (new FacetRegistry())->all();

        $filtered = $base;
        foreach ($facets as $facet) {
            $filtered = $facet->apply($filtered, $query->selected($facet->getName()));
        }

        // Facets filter through many-many / variation joins (ParentID-OR-ProductCategories, Variations.AttributeValues,
        // Colours, …), which can emit duplicate rows for a product that matches a join more than once. Re-assert
        // DISTINCT on the final list so the product grid — and its total count — reflect distinct products, never the
        // inflated join-row count.
        $filtered = $filtered->alterDataQuery(static fn ($dataQuery) => $dataQuery->distinct(true));

        $facetData = [];
        foreach ($facets as $facet) {
            $context = $base;
            foreach ($facets as $other) {
                if ($other === $facet) {
                    continue;
                }
                $context = $other->apply($context, $query->selected($other->getName()));
            }
            $facetData[] = [
                'name' => $facet->getName(),
                'title' => $facet->getTitle(),
                'options' => $facet->getOptions($context, $query),
            ];
        }

        return ['products' => $filtered, 'facets' => $facetData];
    }
}
