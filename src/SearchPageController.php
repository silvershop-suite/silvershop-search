<?php

declare(strict_types=1);

namespace SilverShop\Search;

use PageController;
use SilverShop\Search\Backend\BackendRegistry;
use SilverShop\Search\Facet\FacetRegistry;
use SilverShop\Search\Form\ProductSearchForm;
use SilverShop\Search\Query\SearchQuery;
use SilverShop\Search\View\FacetView;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Model\List\PaginatedList;

/**
 * Runs the query through the active backend and renders results + facets. The search form GETs back here, so the
 * whole state lives in the URL (shareable, cacheable). Facet rendering + toggle links are shared with the
 * on-listing-page faceted navigation via {@link FacetView}.
 */
class SearchPageController extends PageController
{
    private static array $allowed_actions = ['SearchForm'];

    public function index(HTTPRequest $request)
    {
        $facetNames = (new FacetRegistry())->names();
        $pageLength = (int) $this->data()->config()->get('products_per_page');
        $query = SearchQuery::fromRequest($request, $facetNames, $pageLength);

        $results = (new BackendRegistry())->getActive()->query($query);
        $this->recordSearch($query, $results->total);

        $paginated = PaginatedList::create($results->hits, $request)->setPageLength($pageLength);

        return $this->customise([
            'Query' => $query->terms,
            'Total' => $results->total,
            'Results' => $paginated,
            'Facets' => FacetView::build($results->facets, $request, $this->Link()),
            'SearchForm' => $this->SearchForm(),
        ]);
    }

    public function SearchForm(): ProductSearchForm
    {
        return ProductSearchForm::create($this, 'SearchForm');
    }

    /**
     * Hand the query + result count to silvershop-suite/analytics when it is installed (unblocks its search-term
     * reports). Dormant — and non-fatal — when analytics is absent.
     */
    private function recordSearch(SearchQuery $query, int $total): void
    {
        if (!$query->hasTerms()) {
            return;
        }
        $recorder = 'SilverShop\\Analytics\\SearchInsights';
        if (method_exists($recorder, 'record')) {
            $recorder::record($query->terms, $total);
        }
    }
}
