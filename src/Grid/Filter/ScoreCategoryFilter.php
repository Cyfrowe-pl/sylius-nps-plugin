<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Grid\Filter;

use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filtering\FilterInterface;

/**
 * Filters by promoter, passive or detractor. No stock filter can express this: the category is not
 * a column but a band of scores, and the bands are defined once in ScoreCategory - so moving a
 * threshold moves the filter with it.
 */
final class ScoreCategoryFilter implements FilterInterface
{
    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        if (null === $data || [] === $data || '' === $data) {
            return;
        }

        /** @var string $field */
        $field = $options['field'] ?? $name;

        $expressionBuilder = $dataSource->getExpressionBuilder();
        $bands = [];

        foreach ((array) $data as $value) {
            $category = ScoreCategory::tryFrom((string) $value);

            if (null === $category) {
                continue;
            }

            [$from, $to] = $category->scoreRange();

            $bands[] = $expressionBuilder->andX(
                $expressionBuilder->greaterThanOrEqual($field, $from),
                $expressionBuilder->lessThanOrEqual($field, $to),
            );
        }

        if ([] === $bands) {
            return;
        }

        $dataSource->restrict($expressionBuilder->orX(...$bands));
    }
}
