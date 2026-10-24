<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverShop\Model\Variation\AttributeType;
use SilverShop\Model\Variation\Variation;
use SilverShop\Search\Query\SearchQuery;
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
class AttributeTypeFacet implements SearchFacet
{
    /**
     * The attribute type(s) this facet covers, ordered by id (lowest first) for a stable facet name.
     *
     * @var array<int, AttributeType>
     */
    private readonly array $types;

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
        $productIds = $context->columnUnique('ID');
        if (!$productIds) {
            return [];
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

        return array_map('count', $productsByValue);
    }
}
