<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Grid\Filter;

use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filtering\FilterInterface;

/**
 * Whether a column holds anything at all - a comment, a score - with a neutral state that filters
 * nothing.
 *
 * Sylius' own exists filter has no such state: it stops only at null, and its form renders without
 * an empty option, so an untouched filter submits "no" and quietly narrows the list to the records
 * that have neither. The values are words rather than booleans on purpose, because that is the
 * shape of the trap: in a query string an unset choice arrives as '', which is not null, and casts
 * to the same false as '0'.
 */
final class PresenceFilter implements FilterInterface
{
    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        if (self::PRESENT !== $data && self::ABSENT !== $data) {
            return;
        }

        /** @var string $field */
        $field = $options['field'] ?? $name;

        $expressionBuilder = $dataSource->getExpressionBuilder();

        $dataSource->restrict(
            self::PRESENT === $data ? $expressionBuilder->isNotNull($field) : $expressionBuilder->isNull($field),
        );
    }
}
