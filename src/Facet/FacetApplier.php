<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use Psr\SimpleCache\CacheInterface;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\Search\Query\SearchQuery;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataList;

/**
 * Applies the registered facets to any product list and computes each facet's counted options. Shared by the search
 * backend and by the on-listing-page faceted navigation (category / brand pages), so all surfaces narrow and count
 * the same way. Each facet's options are counted against the base with every OTHER facet's selection applied, so a
 * facet keeps showing its alternatives after you pick one of its values.
 *
 * Two surfaces consume this differently: the product grid needs only the narrowed list ({@see applyProducts()}),
 * while the sidebar needs only the counted options ({@see facetData()}). They are split so the grid path never pays
 * for the counting pass, and so the counting pass — identical for every visitor of a given scope + active-filter
 * combination, and changing only when the catalogue does — can be cached (see {@see $cache_facets}).
 */
class FacetApplier
{
    use Configurable;

    /**
     * Cache the computed facet sidebar (every facet's counted options) per scope + active filters + catalogue
     * version. The sidebar is identical for every visitor of a given category + filter combination and the counting
     * pass is the bulk of a faceted listing's queries, so a cache hit skips it entirely. Turn off to always recompute
     * (e.g. while debugging counts, or when stock/price/review changes must show instantly — see {@see $cache_ttl}).
     */
    private static bool $cache_facets = true;

    /**
     * Seconds a cached sidebar stays valid. The version key busts the cache when a product or category is
     * added/edited/removed; this TTL is the backstop for changes it can't see cheaply per request — stock levels,
     * cart reservations and reviews, which shift a count without touching a product's LastEdited. 0 = no expiry
     * (rely on the version key and a `?flush` only). Ignored when {@see $cache_facets} is off.
     */
    private static int $cache_ttl = 300;

    /**
     * Narrow $base by the query's facet selections and return the product list for the grid. No option counting, so
     * the grid path never pays for the sidebar. The list stays lazy and live — it is never cached.
     *
     * @param array<int, SearchFacet>|null $facets the registered facets, or null to load them
     */
    public static function applyProducts(DataList $base, SearchQuery $query, ?array $facets = null): DataList
    {
        $facets ??= (new FacetRegistry())->all();

        $filtered = $base;
        foreach ($facets as $facet) {
            $filtered = $facet->apply($filtered, $query->selected($facet->getName()));
        }

        // Facets filter through many-many / variation joins (ParentID-OR-ProductCategories, Variations.AttributeValues,
        // Colours, …), which can emit duplicate rows for a product that matches a join more than once. Re-assert
        // DISTINCT on the final list so the product grid — and its total count — reflect distinct products, never the
        // inflated join-row count.
        return $filtered->alterDataQuery(static fn ($dataQuery) => $dataQuery->distinct(true));
    }

    /**
     * The counted options for every facet: for each facet, its options counted against the base with every OTHER
     * facet's selection applied. This is the expensive pass, so it is cached per (scope + active filters + catalogue
     * version) when {@see $cache_facets} is on; a hit returns the stored array without touching the database.
     *
     * @param array<int, SearchFacet>|null $facets the registered facets, or null to load them
     *
     * @return array<int, array<string, mixed>>
     */
    public static function facetData(DataList $base, SearchQuery $query, ?array $facets = null): array
    {
        $facets ??= (new FacetRegistry())->all();

        if (!static::config()->get('cache_facets')) {
            return self::computeFacetData($base, $query, $facets);
        }

        $key = self::cacheKey($base, $query, $facets);
        if ($key === null) {
            // Couldn't build a stable key — compute fresh rather than risk serving another scope's counts.
            return self::computeFacetData($base, $query, $facets);
        }

        $cache = self::cache();
        $hit = $cache->get($key);
        if (is_array($hit)) {
            return $hit;
        }

        $facetData = self::computeFacetData($base, $query, $facets);

        $ttl = (int) static::config()->get('cache_ttl');
        $cache->set($key, $facetData, $ttl > 0 ? $ttl : null);

        return $facetData;
    }

    /**
     * Narrowed product list and counted facet options together. Kept for callers that need both in one pass (the
     * search backend); category pages use {@see applyProducts()} / {@see facetData()} directly so each surface pays
     * only for what it uses.
     *
     * @return array{products: DataList, facets: array<int, array<string, mixed>>}
     */
    public static function apply(DataList $base, SearchQuery $query): array
    {
        $facets = (new FacetRegistry())->all();

        return [
            'products' => self::applyProducts($base, $query, $facets),
            'facets' => self::facetData($base, $query, $facets),
        ];
    }

    /**
     * Run the counting pass (uncached).
     *
     * @param array<int, SearchFacet> $facets
     *
     * @return array<int, array<string, mixed>>
     */
    private static function computeFacetData(DataList $base, SearchQuery $query, array $facets): array
    {
        // Fresh sidebar build: drop the attribute dimensions' shared value-count cache so this build's counts reflect
        // the current context/catalogue, never a prior request's.
        AttributeTypeFacet::flushValueCountCache();

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

        return $facetData;
    }

    private static function cache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.silvershopFacets');
    }

    /**
     * A stable cache key for the whole sidebar: the base list's SQL (the scope — which category / search terms), the
     * active facet selections, the set of registered facets, the catalogue version and the locale. Any of these
     * changing yields a different key. Returns null if the base SQL can't be built, so the caller computes fresh
     * instead of risking a collision.
     *
     * @param array<int, SearchFacet> $facets
     */
    private static function cacheKey(DataList $base, SearchQuery $query, array $facets): ?string
    {
        try {
            $params = [];
            $baseSql = $base->dataQuery()->query()->sql($params);
        } catch (\Throwable $e) {
            return null;
        }

        $selected = [];
        $names = [];
        foreach ($facets as $facet) {
            $name = $facet->getName();
            $names[] = $name;
            $values = $query->selected($name);
            if ($values) {
                $selected[$name] = array_values($values);
            }
        }
        ksort($selected);

        return 'facets_' . md5((string) json_encode([
            'sql' => $baseSql,
            'params' => $params,
            'selected' => $selected,
            'facets' => $names,
            'version' => self::catalogueVersion(),
        ]));
    }

    /**
     * A cheap fingerprint of the catalogue that changes when any product or category is added, edited or removed:
     * the max LastEdited and record count of each, plus the current locale. A few indexed aggregate queries — paid
     * even on a cache hit, but negligible beside the counting pass a hit skips. Deliberately does NOT probe stock,
     * cart or review tables (counting those every request would defeat the cache); {@see $cache_ttl} is their
     * backstop.
     */
    private static function catalogueVersion(): string
    {
        $parts = [];
        foreach ([Product::class, ProductCategory::class] as $class) {
            $list = $class::get();
            $parts[] = $class . ':' . (string) $list->max('LastEdited') . ':' . $list->count();
        }

        // Per locale: the sidebar's labels (translated category / attribute titles) and per-locale published set
        // differ, so the locale is part of the version — otherwise one locale would serve another's cached sidebar.
        // tractorcow/silverstripe-fluent (CMS4+) namespaces FluentState under TractorCow; the legacy SilverShop\Fluent
        // name is kept as a fallback for very old installs.
        foreach (['TractorCow\\Fluent\\State\\FluentState', 'SilverStripe\\Fluent\\State\\FluentState'] as $fluentState) {
            if (class_exists($fluentState)) {
                $parts[] = 'locale:' . (string) $fluentState::singleton()->getLocale();
                break;
            }
        }

        return implode('|', $parts);
    }
}
