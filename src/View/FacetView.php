<?php

declare(strict_types=1);

namespace SilverShop\Search\View;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * Turns the facet data from {@link \SilverShop\Search\Facet\FacetApplier} into template-ready rows, each option
 * carrying a toggle Link scoped to a given base URL. Shared by the search results page and the on-listing-page
 * faceted navigation, so facet links behave identically everywhere (OR within a facet, paging reset, nofollow).
 */
class FacetView
{
    /**
     * @param array<int, array<string, mixed>> $facetData
     */
    public static function build(array $facetData, HTTPRequest $request, string $baseLink): ArrayList
    {
        $list = ArrayList::create();
        foreach ($facetData as $facet) {
            // Hide a facet that has no options for the current result set (e.g. an attribute no product in this
            // category carries). An empty section would render an empty, confusing heading, so skip it entirely.
            if (empty($facet['options'])) {
                continue;
            }
            $options = ArrayList::create();
            foreach ($facet['options'] as $option) {
                $options->push(ArrayData::create([
                    'Value' => $option['value'],
                    'Label' => $option['label'],
                    'Count' => $option['count'],
                    'Active' => $option['active'],
                    // Optional CSS colour for a swatch chip (e.g. the colour facet); null for ordinary facets.
                    'Swatch' => $option['swatch'] ?? null,
                    'Link' => self::toggleLink($request, $baseLink, $facet['name'], (string) $option['value']),
                ]));
            }
            $list->push(ArrayData::create([
                'Name' => $facet['name'],
                'Title' => $facet['title'],
                'Options' => $options,
            ]));
        }

        return $list;
    }

    /**
     * The given base URL with one facet value toggled on/off (OR within a facet), paging reset.
     */
    public static function toggleLink(HTTPRequest $request, string $baseLink, string $facet, string $value): string
    {
        $vars = self::cleanVars($request);

        $current = isset($vars[$facet])
            ? array_values(array_filter(array_map('trim', explode(',', (string) $vars[$facet])), 'strlen'))
            : [];

        if (in_array($value, $current, true)) {
            $current = array_values(array_diff($current, [$value]));
        } else {
            $current[] = $value;
        }

        if ($current) {
            $vars[$facet] = implode(',', $current);
        } else {
            unset($vars[$facet]);
        }

        $qs = http_build_query($vars);

        return $baseLink . ($qs ? '?' . $qs : '');
    }

    /**
     * Request GET vars minus routing/form noise, so rebuilt links stay clean.
     *
     * @return array<string, mixed>
     */
    private static function cleanVars(HTTPRequest $request): array
    {
        $vars = $request->getVars();
        unset($vars['url'], $vars['flush'], $vars['start']);
        foreach (array_keys($vars) as $key) {
            if (str_starts_with((string) $key, 'action_')) {
                unset($vars[$key]);
            }
        }

        return $vars;
    }
}
