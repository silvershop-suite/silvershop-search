<?php

declare(strict_types=1);

namespace SilverShop\Search\Facet;

use SilverShop\Search\Query\SearchQuery;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Intrinsic price-range facet over Product.BasePrice. Buckets are configurable; values are encoded "min-max"
 * ("100-" for an open top bucket). Multiple selected buckets are OR'd. Ships in the search core because price is
 * core catalog data with no owning optional module.
 */
class PriceFacet implements SearchFacet
{
    use Configurable;

    /**
     * Price buckets as [min, max] (max null/omitted = open-ended top bucket).
     *
     * @var array<int, array<int, int|float|null>>
     */
    private static array $buckets = [
        [0, 25],
        [25, 50],
        [50, 100],
        [100, null],
    ];

    public function getName(): string
    {
        return 'price';
    }

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Price');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function apply(DataList $products, array $selected): DataList
    {
        $clauses = [];
        $params = [];
        foreach ($selected as $value) {
            [$min, $max] = $this->decode((string) $value);
            if ($min === null) {
                continue;
            }
            if ($max === null) {
                $clauses[] = '"BasePrice" >= ?';
                $params[] = $min;
            } else {
                $clauses[] = '("BasePrice" >= ? AND "BasePrice" < ?)';
                $params[] = $min;
                $params[] = $max;
            }
        }

        if (!$clauses) {
            return $products;
        }

        return $products->where([implode(' OR ', $clauses) => $params]);
    }

    public function getOptions(DataList $context, SearchQuery $query): array
    {
        $selected = array_map('strval', $query->selected($this->getName()));
        $options = [];

        foreach ((array) self::config()->get('buckets') as $bucket) {
            $min = (float) ($bucket[0] ?? 0);
            $max = array_key_exists(1, $bucket) && $bucket[1] !== null ? (float) $bucket[1] : null;
            $value = $this->encode($min, $max);
            $count = (int) $this->applyRange($context, $min, $max)->count();
            $active = in_array($value, $selected, true);

            if ($count === 0 && !$active) {
                continue;
            }

            $options[] = [
                'value' => $value,
                'label' => $this->label($min, $max),
                'count' => $count,
                'active' => $active,
            ];
        }

        return $options;
    }

    private function applyRange(DataList $list, float $min, ?float $max): DataList
    {
        $list = $list->filter('BasePrice:GreaterThanOrEqual', $min);
        if ($max !== null) {
            $list = $list->filter('BasePrice:LessThan', $max);
        }

        return $list;
    }

    private function encode(float $min, ?float $max): string
    {
        return $max === null ? $this->num($min) . '-' : $this->num($min) . '-' . $this->num($max);
    }

    /**
     * @return array{0: ?float, 1: ?float} [min, max] (max null = open); [null, null] when unparseable
     */
    private function decode(string $value): array
    {
        if (!str_contains($value, '-')) {
            return [null, null];
        }
        [$min, $max] = explode('-', $value, 2);
        if (!is_numeric($min)) {
            return [null, null];
        }

        return [(float) $min, is_numeric($max) ? (float) $max : null];
    }

    private function label(float $min, ?float $max): string
    {
        if ($min <= 0 && $max !== null) {
            return _t(self::class . '.UNDER', 'Under {max}', ['max' => $this->formatAmount($max)]);
        }
        if ($max === null) {
            return _t(self::class . '.PLUS', '{min} and up', ['min' => $this->formatAmount($min)]);
        }

        return _t(self::class . '.RANGE', '{min} – {max}', [
            'min' => $this->formatAmount($min),
            'max' => $this->formatAmount($max),
        ]);
    }

    private function formatAmount(float $amount): string
    {
        // Format through the shop's own `Currency` DBField cast — the same one Product.BasePrice uses for the card
        // prices — so the buckets match (symbol, placement, grouping/decimals in the shop locale). When
        // silvershop/currency-format is installed it maps `Currency` to LocaleCurrency (e.g. "€ 25,00"); otherwise
        // it is silvershop's ShopCurrency. Either way the sidebar matches the grid, instead of core DBCurrency's "$".
        // 'Currency' resolves (via Injector) to DBCurrency or a shop subclass, all of which expose Nice().
        /** @var \SilverStripe\ORM\FieldType\DBCurrency $field */
        $field = DBField::create_field('Currency', $amount);

        return $field->Nice();
    }

    /**
     * Compact numeric string (no trailing .0) for URL values.
     */
    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
