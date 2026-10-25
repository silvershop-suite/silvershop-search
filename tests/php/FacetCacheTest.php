<?php

namespace SilverShop\Search\Tests;

use Psr\SimpleCache\CacheInterface;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\Search\Facet\FacetApplier;
use SilverShop\Search\Query\SearchQuery;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The facet sidebar cache ({@see FacetApplier::facetData()}): identical counts cached vs uncached, a hit is served
 * until the catalogue version changes, and the config toggle disables caching.
 */
class FacetCacheTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Start every test from an empty pool — the cache backend outlives the per-test DB transaction.
        Injector::inst()->get(CacheInterface::class . '.silvershopFacets')->clear();
        Config::modify()->set(FacetApplier::class, 'cache_facets', true);
        Config::modify()->set(FacetApplier::class, 'cache_ttl', 0);
    }

    private function seed(): array
    {
        $a = ProductCategory::create(['Title' => 'Alpha', 'URLSegment' => 'alpha']);
        $a->write();
        $b = ProductCategory::create(['Title' => 'Beta', 'URLSegment' => 'beta']);
        $b->write();
        foreach ([10, 30, 90] as $price) {
            Product::create([
                'Title' => 'P' . $price,
                'BasePrice' => $price,
                'ParentID' => $a->ID,
                'AllowPurchase' => true,
            ])->write();
        }

        return [$a, $b];
    }

    private function option(array $facetData, string $facetName, string $value): ?array
    {
        foreach ($facetData as $facet) {
            if ($facet['name'] !== $facetName) {
                continue;
            }
            foreach ($facet['options'] as $option) {
                if ((string) $option['value'] === $value) {
                    return $option;
                }
            }
        }

        return null;
    }

    public function testCachedAndUncachedCountsMatch(): void
    {
        $this->seed();
        $query = new SearchQuery();

        $cached = FacetApplier::facetData(Product::get(), $query);

        Config::modify()->set(FacetApplier::class, 'cache_facets', false);
        $fresh = FacetApplier::facetData(Product::get(), $query);

        $this->assertEquals($fresh, $cached, 'the cached sidebar is identical to a fresh compute');
    }

    public function testHitIsServedUntilCatalogueVersionChanges(): void
    {
        [, $beta] = $this->seed();
        $query = new SearchQuery();

        // Warm the cache: Beta is empty, so it has no option yet.
        $first = FacetApplier::facetData(Product::get(), $query);
        $this->assertNull($this->option($first, 'category', 'beta'), 'Beta starts empty');

        // Add a product to Beta via the secondary-membership many-many. This changes Beta's count but touches
        // neither Product/ProductCategory record counts nor their LastEdited — so the catalogue version is unchanged.
        Product::get()->first()->ProductCategories()->add($beta);

        $hit = FacetApplier::facetData(Product::get(), $query);
        $this->assertNull(
            $this->option($hit, 'category', 'beta'),
            'the stored sidebar is served (Beta still absent) while the version is unchanged'
        );

        // A new product bumps the Product count → the version changes → the cache recomputes and sees Beta.
        Product::create(['Title' => 'Pnew', 'BasePrice' => 5, 'ParentID' => $beta->ID, 'AllowPurchase' => true])->write();

        $recomputed = FacetApplier::facetData(Product::get(), $query);
        $betaOption = $this->option($recomputed, 'category', 'beta');
        $this->assertNotNull($betaOption, 'after the version changes Beta is counted');
        $this->assertSame(2, (int) $betaOption['count'], 'Beta now has the added membership plus the new product');
    }

    public function testDisabledCacheReflectsChangesImmediately(): void
    {
        [, $beta] = $this->seed();
        Config::modify()->set(FacetApplier::class, 'cache_facets', false);
        $query = new SearchQuery();

        $this->assertNull($this->option(FacetApplier::facetData(Product::get(), $query), 'category', 'beta'));

        Product::get()->first()->ProductCategories()->add($beta);

        $betaOption = $this->option(FacetApplier::facetData(Product::get(), $query), 'category', 'beta');
        $this->assertNotNull($betaOption, 'with caching off the membership change shows at once');
        $this->assertSame(1, (int) $betaOption['count']);
    }
}
