<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverShop\Search\Query\SearchQuery;
use SilverStripe\ORM\DataList;

/**
 * One filter dimension on a product result list (brand, price, rating, …). The search core ships the intrinsic
 * facets (price, attributes); optional modules ship their own — a brand facet in silvershop-suite/brands, a rating
 * facet in silvershop-suite/reviews, etc. — and register them into {@link FacetRegistry}. The results controller is
 * module-agnostic: it never names a concrete Brand/Review, it just drives whatever facets are registered.
 */
interface SearchFacet
{
    /**
     * Machine name — the request var and array key for this facet, e.g. 'brand'.
     */
    public function getName(): string;

    /**
     * Human label for the UI, e.g. 'Brand'.
     */
    public function getTitle(): string;

    /**
     * Whether this facet can be offered (e.g. its module/data is present). Unavailable facets are skipped.
     */
    public function isAvailable(): bool;

    /**
     * Narrow the product list to the given selected values (empty = no narrowing). Several values are OR'd.
     *
     * @param array<int|string> $selected
     */
    public function apply(DataList $products, array $selected): DataList;

    /**
     * The facet's options with a count each, computed against $context (the result set with every OTHER facet's
     * selection already applied). Returns a list of ['value', 'label', 'count', 'active'].
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOptions(DataList $context, SearchQuery $query): array;
}
