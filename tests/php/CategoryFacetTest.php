<?php

namespace SilverShop\Search\Tests;

use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\Search\Facet\CategoryFacet;
use SilverShop\Search\Query\SearchQuery;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises the core-native path (silvershop-suite/category-index is not installed in this module's tests, so the facet
 * walks ParentID/descendants via core). The faster index path is covered by live verification on an installed site.
 */
class CategoryFacetTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private function seed(): void
    {
        $mens = ProductCategory::create(['Title' => 'Mens', 'URLSegment' => 'mens']);
        $mens->write();
        $jackets = ProductCategory::create(['Title' => 'Jackets', 'URLSegment' => 'jackets', 'ParentID' => $mens->ID]);
        $jackets->write();
        $hats = ProductCategory::create(['Title' => 'Hats', 'URLSegment' => 'hats', 'ParentID' => $mens->ID]);
        $hats->write();

        Product::create(['Title' => 'Rain Jacket', 'BasePrice' => 89, 'ParentID' => $jackets->ID])->write();
        Product::create(['Title' => 'Sun Hat', 'BasePrice' => 19, 'ParentID' => $hats->ID])->write();
        Product::create(['Title' => 'Mens Thing', 'BasePrice' => 29, 'ParentID' => $mens->ID])->write();
    }

    public function testApplyMatchesCategoryAndDescendants(): void
    {
        $this->seed();
        $facet = new CategoryFacet();

        $this->assertSame(3, $facet->apply(Product::get(), ['mens'])->count(), 'a parent category includes descendants');
        $this->assertSame(1, $facet->apply(Product::get(), ['jackets'])->count());
        $this->assertSame(2, $facet->apply(Product::get(), ['jackets', 'hats'])->count(), 'multiple categories OR-ed');
    }

    public function testUnknownSegmentYieldsNoProducts(): void
    {
        $this->seed();

        $this->assertSame(0, (new CategoryFacet())->apply(Product::get(), ['ghost'])->count());
    }

    public function testApplyWithNoSelectionIsUnfiltered(): void
    {
        $this->seed();

        $this->assertSame(3, (new CategoryFacet())->apply(Product::get(), [])->count());
    }

    public function testOptionsCarryCountsAndActiveFlag(): void
    {
        $this->seed();

        $options = (new CategoryFacet())->getOptions(Product::get(), new SearchQuery(filters: ['category' => ['jackets']]));
        $byValue = [];
        foreach ($options as $option) {
            $byValue[$option['value']] = $option;
        }

        $this->assertSame(3, $byValue['mens']['count'], 'parent counts its whole subtree');
        $this->assertSame(1, $byValue['jackets']['count']);
        $this->assertTrue($byValue['jackets']['active']);
        $this->assertFalse($byValue['mens']['active']);
    }
}
