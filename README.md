# SilverShop Search

Faceted product search & navigation for [SilverShop](https://github.com/silvershop/silvershop-core) (Silverstripe CMS 6).

SilverShop core ships no storefront search and no multi-facet refine. This module adds both: a search results page, a
**pluggable search backend** (a zero-infrastructure database backend by default), and a **facet framework** that other
modules plug their own facets into.

## Requirements

- Silverstripe CMS `^6.0`
- SilverShop core `^6`

## Installation

```bash
composer require silvershop-suite/search
```

Run `dev/build?flush=1`, then add a **Search Page** in the CMS. Its form submits a query (GET) back to itself and the
page renders faceted, sorted, paginated results.

## Architecture

```
silvershop-suite/search  ──  SearchBackend (interface)  ──  DbSearchBackend   (default, no infra)
                                                   └─  AlgoliaBackend…   (separate adapter modules, later)
                    ──  SearchFacet  (interface)  ──  CategoryFacet, PriceFacet   (intrinsic, here)
                        + FacetRegistry            └─  BrandFacet (brands), RatingFacet (reviews), … (in their modules)
```

- **Backends** run a query and return results. The default `DbSearchBackend` queries the product tables directly —
  correct and dependency-free, but no typo-tolerance/synonyms/instant-search (those come from engine adapters).
- **Facets** are one filter dimension each. The core ships the *intrinsic* ones (category, price, and — soon — attributes),
  because they are core catalog data. Facets that belong to an optional module **live in that module** (the same
  convention as the suite's domain reports).

## Adding a facet from another module

Ship a `SearchFacet` in the owning module and register it behind a guard, with `silvershop-suite/search` as a **`suggest`**
(not a hard requirement) so the module stays standalone:

```php
// src/Search/BrandFacet.php in silvershop-suite/brands
class BrandFacet implements \SilverShop\Search\Facet\SearchFacet { /* getName()='brand', apply(), getOptions() … */ }
```

```yaml
# _config/search.yml in silvershop-suite/brands — only registers when search is installed
---
Name: silvershop-brands-search
Only:
  moduleexists: silvershop-suite/search
---
SilverShop\Search\Facet\FacetRegistry:
  facets:
    brand: '%$SilverShop\Brands\Search\BrandFacet'
```

The search core never references `Brand`; it just drives whatever facets are registered.

## Configuration

```yaml
SilverShop\Search\Facet\FacetRegistry:
  facets:                       # name => service, in display order
    price: '%$SilverShop\Search\Facet\PriceFacet'

SilverShop\Search\Facet\PriceFacet:
  buckets:                      # [min, max]; omit/null max for an open top bucket
    - [0, 25]
    - [25, 50]
    - [50, 100]
    - [100, ~]

SilverShop\Search\Backend\DbSearchBackend:
  search_fields: ['Title', 'Content', 'InternalItemID', 'Model']
  only_purchasable: false       # true = only AllowPurchase products are searchable

SilverShop\Search\Backend\BackendRegistry:
  active_backend: 'db'
```

Facet state lives entirely in the URL (`?q=jacket&price=25-50&sort=price_asc`) — orderless, multi-select (OR within a
facet, AND across), and shareable. Facet links are `rel=nofollow` to keep crawlers out of the combinatorial space.

## Integrations (all soft / optional)

- **silvershop-suite/category-index** — not required: the category facet works on core alone, but when this module is
  installed it uses the flat `EffectiveCategories` index (one JOIN) instead of core's per-level tree walk — faster,
  especially for facet counts.
- **brands**, **reviews**, **stock** — each ships its own facet (brand / rating / stock), registered only when both
  it and search are installed.
- **silvershop-suite/analytics** — search calls its `record()` hook so search terms and zero-result queries feed the
  analytics reports. Dormant until analytics is installed.
- **silvershop-suite/currency-format** — price-bucket labels respect the shop currency.

## Roadmap

- Attribute facets (from core product variations).
- On-category faceted navigation (same facet engine, mounted on `ProductCategory` via `updateProductsShowable`).
- External-engine backends (Algolia / Meilisearch / Typesense / `silverstripe/discoverer`) as separate adapter modules.

## License

BSD-3-Clause. See [LICENSE](LICENSE).

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
