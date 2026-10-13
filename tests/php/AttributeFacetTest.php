<?php

namespace SilverShop\Search\Tests;

use SilverShop\Model\Variation\AttributeType;
use SilverShop\Model\Variation\AttributeValue;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Search\Facet\AttributeFacet;
use SilverShop\Search\Facet\AttributeTypeFacet;
use SilverShop\Search\Query\SearchQuery;
use SilverStripe\Dev\SapphireTest;

class AttributeFacetTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private AttributeType $colour;
    private int $red;
    private int $blue;

    private function seed(): void
    {
        $this->colour = AttributeType::create(['Name' => 'Colour', 'Label' => 'Colour']);
        $this->colour->write();
        $red = AttributeValue::create(['Value' => 'Red', 'TypeID' => $this->colour->ID]);
        $red->write();
        $blue = AttributeValue::create(['Value' => 'Blue', 'TypeID' => $this->colour->ID]);
        $blue->write();
        $this->red = (int) $red->ID;
        $this->blue = (int) $blue->ID;

        $this->variedProduct('Red Shirt', $red->ID);
        $this->variedProduct('Blue Shirt', $blue->ID);
        Product::create(['Title' => 'Plain', 'BasePrice' => 20])->write();
    }

    private function variedProduct(string $title, int $valueID): void
    {
        $product = Product::create(['Title' => $title, 'BasePrice' => 20]);
        $product->write();
        $variation = Variation::create(['ProductID' => $product->ID]);
        $variation->write();
        $variation->AttributeValues()->add($valueID);
    }

    public function testExpandsToOneFacetPerType(): void
    {
        $this->seed();
        $provider = new AttributeFacet();

        $this->assertTrue($provider->isAvailable());
        $facets = $provider->expand();
        $this->assertCount(1, $facets);
        $this->assertSame('attr-' . (int) $this->colour->ID, $facets[0]->getName());
    }

    public function testUnavailableWithoutAttributeTypes(): void
    {
        $this->assertFalse((new AttributeFacet())->isAvailable(), 'no types defined → no attribute facets');
    }

    public function testMergesTypesWithTheSameLabel(): void
    {
        // Two distinct types that resolve to the same front-end label ("Size") must collapse into ONE facet whose
        // options are the union of both value sets — so the sidebar never shows "Size" twice.
        $shirtSize = AttributeType::create(['Name' => 'Shirt Size', 'Label' => 'Size']);
        $shirtSize->write();
        $small = AttributeValue::create(['Value' => 'Small', 'TypeID' => $shirtSize->ID]);
        $small->write();

        $size = AttributeType::create(['Name' => 'Size', 'Label' => 'Size']);
        $size->write();
        $s = AttributeValue::create(['Value' => 'S', 'TypeID' => $size->ID]);
        $s->write();

        $facets = (new AttributeFacet())->expand();
        $this->assertCount(1, $facets, 'the two "Size" types merge into a single facet');
        $this->assertSame('attr-' . (int) $shirtSize->ID, $facets[0]->getName(), 'named by the lowest type id');

        // With a product per value, the merged facet offers values from BOTH types under one heading.
        $this->variedProduct('Small Shirt', (int) $small->ID);
        $this->variedProduct('S Shirt', (int) $s->ID);
        $merged = $facets[0]->getOptions(Product::get(), new SearchQuery());
        $values = array_map(static fn (array $o): string => $o['label'], $merged);
        $this->assertContains('Small', $values);
        $this->assertContains('S', $values, 'the merged facet offers values from BOTH types');
    }


    public function testApplyMatchesProductsWithTheValue(): void
    {
        $this->seed();
        $facet = new AttributeTypeFacet($this->colour);

        $this->assertSame(1, $facet->apply(Product::get(), [(string) $this->red])->count());
        $this->assertSame(2, $facet->apply(Product::get(), [(string) $this->red, (string) $this->blue])->count());
        $this->assertSame(3, $facet->apply(Product::get(), [])->count(), 'no selection = unfiltered');
    }

    public function testOptionsCarryCountsAndActiveFlag(): void
    {
        $this->seed();

        $options = (new AttributeTypeFacet($this->colour))->getOptions(
            Product::get(),
            new SearchQuery(filters: ['attr-' . (int) $this->colour->ID => [(string) $this->red]])
        );
        $byValue = [];
        foreach ($options as $option) {
            $byValue[$option['value']] = $option;
        }

        $this->assertSame(1, $byValue[(string) $this->red]['count']);
        $this->assertTrue($byValue[(string) $this->red]['active']);
        $this->assertSame(1, $byValue[(string) $this->blue]['count']);
        $this->assertFalse($byValue[(string) $this->blue]['active']);
    }
}
