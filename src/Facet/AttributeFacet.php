<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverShop\Model\Variation\AttributeType;
use SilverShop\Model\Variation\AttributeValue;

/**
 * Expands into one {@link AttributeTypeFacet} per product attribute dimension (Colour, Size, …) defined in the shop.
 * Self-guarding: contributes nothing when no attribute types exist, so it only appears on shops that use product
 * variations. Attribute types come from silvershop/core's variation support, which is always present.
 *
 * Two kinds of de-duplication keep the sidebar clean:
 *  - Types that resolve to the SAME front-end label are merged into one facet (e.g. a "Shirt Size" type and a
 *    generic "Size" type both labelled "Size" become a single "Size" facet covering both sets of values).
 *  - A type whose values are a specialised {@link AttributeValue} subclass is skipped, because a dedicated facet
 *    already surfaces it — e.g. a colour type backed by silvershop/colors' ShopColor is shown by that module's
 *    swatch ColorFacet, not here. This avoids a second, plain "Colour" facet alongside the swatch one.
 */
class AttributeFacet implements ExpandableFacet
{
    public function isAvailable(): bool
    {
        return AttributeType::get()->exists();
    }

    public function expand(): array
    {
        // Group the owned types by their resolved facet title, in first-seen order.
        $groups = [];
        foreach (AttributeType::get() as $type) {
            if ($this->isSupersededByDedicatedFacet($type)) {
                continue;
            }
            $title = (string) ($type->Label ?: $type->Name);
            $groups[$title][] = $type;
        }

        $facets = [];
        foreach ($groups as $types) {
            $facets[] = new AttributeTypeFacet($types);
        }

        return $facets;
    }

    /**
     * A type is surfaced by a dedicated facet (not this generic one) when all its values are a specialised subclass
     * of {@link AttributeValue} — e.g. silvershop/colors' ShopColor, handled by the swatch ColorFacet. Types with
     * plain AttributeValue values (or no values yet) stay with the generic attribute facet.
     */
    private function isSupersededByDedicatedFacet(AttributeType $type): bool
    {
        $values = $type->Values();
        if (!$values->exists()) {
            return false;
        }

        foreach ($values as $value) {
            if (get_class($value) === AttributeValue::class) {
                return false;
            }
        }

        return true;
    }
}
