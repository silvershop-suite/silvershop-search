<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverShop\Model\Variation\AttributeType;
use SilverShop\Model\Variation\Variation;
use SilverShop\Search\Query\SearchQuery;
use SilverStripe\Core\Resettable;
use SilverStripe\ORM\DataList;

/**
 * One attribute dimension as a facet (e.g. Colour, with options Red/Blue). Matches products that have a variation
 * carrying the selected attribute value(s): Product → Variations → AttributeValues. Values within the facet are
 * OR-ed; different attribute dimensions (separate facets) AND together. The title is the type's front-end Label.
 *
 * A facet may cover MORE THAN ONE {@link AttributeType} when several resolve to the same front-end label (e.g. a
 * "Shirt Size" type and a generic "Size" type both labelled "Size"). Those are merged into a single facet whose
 * options are the union of the types' values, so the sidebar never shows the same label twice and filtering matches
 * products using either type's value. Attribute value ids are globally unique, so a single id filter covers them all.
 */
class AttributeTypeFacet implements SearchFacet, Resettable
{
    /**
     * The attribute type(s) this facet covers, ordered by id (lowest first) for a stable facet name.
     *
     * @var array<int, AttributeType>
     */
    private readonly array $types;

    /**
     * Shared per-sidebar cache of the full attribute-value → distinct-product-count map, keyed by the context's
     * query signature. Every attribute dimension (Colour, Size, …) counts over the same context and the map already
     * covers ALL values, so the first dimension builds it (one variation fetch) and the rest reuse it instead of each
     * re-fetching the context's variations. Reset per sidebar build by {@see FacetApplier} so it never serves a stale
     * count across requests.
     *
     * @var array<string, array<int, int>>
     */
    private static array $valueCountCache = [];

    /**
     * Drop the shared value-count cache. Called by {@see FacetApplier::apply()} at the start of each sidebar build.
     */
    public static function flushValueCountCache(): void
    {
        self::$valueCountCache = [];
    }

    /**
     * {@see Resettable}: clear the shared static cache between tests (SapphireTest's FlushableTestState resets every
     * Resettable before each test) and on flush. Production still flushes it per sidebar build via
     * {@see FacetApplier::apply()}; this just guarantees a stale map can never survive across tests or a flush.
     */
    public static function reset(): void
    {
        self::flushValueCountCache();
    }

    /**
     * @param AttributeType|array<int, AttributeType> $types one type, or several that share a front-end label
     */
    public function __construct(AttributeType|array $types)
    {
        $list = is_array($types) ? array_values($types) : [$types];
        usort($list, static fn (AttributeType $a, AttributeType $b): int => (int) $a->ID <=> (int) $b->ID);
        $this->types = $list;
    }

    private function primaryType(): AttributeType
    {
        return $this->types[0];
    }

    public function getName(): string
    {
        return 'attr-' . (int) $this->primaryType()->ID;
    }

    public function getTitle(): string
    {
        $type = $this->primaryType();

        return (string) ($type->Label ?: $type->Name);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function apply(DataList $products, array $selected): DataList
    {
        $ids = array_values(array_filter(array_map('intval', $selected)));
        if (!$ids) {
            return $products;
        }

        return $products->filter('Variations.AttributeValues.ID', $ids);
    }

    public function getOptions(DataList $context, SearchQuery $query): array
    {
        $selected = array_map('strval', $query->selected($this->getName()));

        // One pass over the context's variations instead of a COUNT query per attribute value (N+1): the count of
        // each value is the number of distinct context products that have a variation carrying it.
        $counts = $this->countProductsByValue($context);

        $options = [];
        foreach ($this->types as $type) {
            foreach ($type->Values() as $value) {
                $count = (int) ($counts[(int) $value->ID] ?? 0);
                $optionValue = (string) $value->ID;
                $active = in_array($optionValue, $selected, true);
                if ($count === 0 && !$active) {
                    continue;
                }
                $options[] = [
                    'value' => $optionValue,
                    'label' => (string) $value->Value,
                    'count' => $count,
                    'active' => $active,
                ];
            }
        }

        return $options;
    }

    /**
     * Distinct context-product count per attribute value, built in a couple of queries: the context product ids,
     * then the context's variations with their AttributeValues eager-loaded, counted (distinct product) per value in
     * PHP. Replaces the per-value `$context->filter('Variations.AttributeValues.ID', $id)->count()` N+1.
     *
     * @return array<int, int> attributeValueID => distinct product count
     */
    private function countProductsByValue(DataList $context): array
    {
        // The map covers every attribute value, so it is identical for every dimension counting over this context —
        // compute it once per context and share it (see $valueCountCache).
        $signature = $this->contextSignature($context);
        if (array_key_exists($signature, self::$valueCountCache)) {
            return self::$valueCountCache[$signature];
        }

        $productIds = $context->columnUnique('ID');
        if (!$productIds) {
            return self::$valueCountCache[$signature] = [];
        }

        // productID set per value, so a product with several matching variations is counted once.
        $productsByValue = [];
        $variations = Variation::get()->filter('ProductID', $productIds)->eagerLoad('AttributeValues');
        foreach ($variations as $variation) {
            $productId = (int) $variation->ProductID;
            foreach ($variation->AttributeValues() as $value) {
                $productsByValue[(int) $value->ID][$productId] = true;
            }
        }

        return self::$valueCountCache[$signature] = array_map('count', $productsByValue);
    }

    /**
     * A stable fingerprint of the context list (its SQL + bound params), so two dimensions counting over the same
     * context share one computed map. Falls back to a unique key (no sharing) if the SQL can't be built, so a failure
     * recomputes rather than risking a wrong shared count.
     */
    private function contextSignature(DataList $context): string
    {
        try {
            $params = [];
            $sql = $context->dataQuery()->query()->sql($params);

            return md5($sql . '|' . json_encode($params));
        } catch (\Throwable $e) {
            return uniqid('ctx', true);
        }
    }
}
