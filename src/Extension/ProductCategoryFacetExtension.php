<?php

declare(strict_types=1);

namespace SilverShop\Search\Extension;

use SilverShop\Search\Facet\FacetApplier;
use SilverShop\Search\Facet\FacetRegistry;
use SilverShop\Search\Query\SearchQuery;
use SilverShop\Search\View\FacetView;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;

/**
 * Faceted navigation on category pages. Applied to both ProductCategory (its `updateProductsShowable` hook narrows
 * the listing by the request's facet selections — bookmarkable `/category/?brand=…&price=…`, theme-agnostic) and to
 * ProductCategoryController (exposes `$SearchFacets` for an opt-in sidebar: add it to the category template).
 *
 * The same registered facets drive the category page, the search results page and brand pages, so refinement behaves
 * consistently everywhere.
 */
class ProductCategoryFacetExtension extends Extension
{
    /**
     * Guards against re-entrancy: {@see SearchFacets()} calls ProductsShowable() to get the unfiltered base for
     * counting, which must not itself be narrowed.
     */
    private static bool $suspend = false;

    /**
     * Narrow the category's product listing by the active facet selections (core model hook). Handles the plain-core
     * path, where core builds the list itself and then calls this.
     */
    public function updateProductsShowable(&$products): void
    {
        $this->narrow($products);
    }

    /**
     * Also narrow a list that another module supplied up front via core's `overrideProductsShowable` hook (e.g.
     * silvershop/category-index's EffectiveCategories index). Core returns that override immediately, skipping
     * `updateProductsShowable`, so without this the facet selections would be silently ignored on category pages
     * whenever such a module is installed. This extension is ordered to run after the supplier (see _config), so the
     * override is already set when we narrow it. No-op when nothing supplied an override.
     *
     * @param mixed $override
     */
    public function overrideProductsShowable(&$override, $recursive): void
    {
        $this->narrow($override);
    }

    /**
     * Apply the request's facet selections to a product DataList in place. Guarded against re-entrancy (sidebar
     * counting) and against being called with a non-list / outside a request.
     *
     * @param mixed $products
     */
    private function narrow(&$products): void
    {
        if (self::$suspend || !$products instanceof DataList) {
            return;
        }

        $request = Controller::curr()?->getRequest();
        if (!$request instanceof HTTPRequest) {
            return;
        }

        $products = FacetApplier::apply($products, $this->queryFrom($request))['products'];
    }

    /**
     * Facet sidebar data for the current category (counts against the whole category, before narrowing). Returns null
     * off a category controller or when there are no facets. Opt-in: reference `$SearchFacets` in the category template.
     */
    public function SearchFacets(): ?ArrayList
    {
        $category = $this->owner->hasMethod('data') ? $this->owner->data() : null;
        if (!$category || !$category->hasMethod('ProductsShowable')) {
            return null;
        }

        self::$suspend = true;
        $base = $category->ProductsShowable();
        self::$suspend = false;

        if (!$base instanceof DataList) {
            return null;
        }

        $request = $this->owner->getRequest();
        $data = FacetApplier::apply($base, $this->queryFrom($request));

        return FacetView::build($data['facets'], $request, (string) $category->Link());
    }

    private function queryFrom(HTTPRequest $request): SearchQuery
    {
        return SearchQuery::fromRequest($request, (new FacetRegistry())->names());
    }
}
