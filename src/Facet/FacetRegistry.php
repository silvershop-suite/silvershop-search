<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;

/**
 * Registry of the facets available to the shop. Facets are declared in config (name => Injector service), so each
 * module plugs its own facet in without the search core knowing about it:
 *
 *   SilverShop\Search\Facet\FacetRegistry:
 *     facets:
 *       price: '%$SilverShop\Search\Facet\PriceFacet'
 *       brand: '%$SilverShop\Brands\Search\BrandFacet'   # registered by silvershop-suite/brands (moduleexists-guarded)
 *
 * Order in the config is the display order. Unavailable facets (module/data absent) are filtered out.
 */
class FacetRegistry
{
    use Configurable;

    /**
     * @var array<string, string> facet name => Injector service name / class
     */
    private static array $facets = [];

    /**
     * All available facets, in config order, keyed by name.
     *
     * @return array<string, SearchFacet>
     */
    public function all(): array
    {
        $facets = [];
        foreach ((array) self::config()->get('facets') as $service) {
            if (!$service) {
                continue;
            }
            $facet = Injector::inst()->get($service);

            // A provider expands into zero or more concrete facets (e.g. one per attribute type).
            if ($facet instanceof ExpandableFacet) {
                if (!$facet->isAvailable()) {
                    continue;
                }
                foreach ($facet->expand() as $expanded) {
                    if ($expanded->isAvailable()) {
                        $facets[$expanded->getName()] = $expanded;
                    }
                }
                continue;
            }

            if ($facet instanceof SearchFacet && $facet->isAvailable()) {
                $facets[$facet->getName()] = $facet;
            }
        }

        return $facets;
    }

    /**
     * The registered facet names, in order.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }
}
