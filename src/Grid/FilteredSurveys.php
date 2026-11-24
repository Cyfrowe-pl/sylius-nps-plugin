<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Grid;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\GridBundle\Doctrine\ORM\DataSource;
use Sylius\Component\Grid\Data\DataSourceProviderInterface;
use Sylius\Component\Grid\Definition\Filter;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\Filtering\FiltersApplicatorInterface;
use Sylius\Component\Grid\Parameters;
use Sylius\Component\Grid\Provider\GridProviderInterface;
use Sylius\Component\Grid\View\GridViewInterface;

/**
 * The set of records the admin list is currently showing, as a query builder anything else can
 * build on.
 *
 * Filtering is not reimplemented here. A data source is built from the grid's own definition and
 * the request parameters, and Sylius' own applicator restricts it - so the figures above the list
 * and the file it exports narrow with the list, including by filters added later. Two
 * implementations of the same filtering would be two chances for the screen and the file to
 * disagree, which is precisely the trap: September on screen, everything in the file.
 *
 * Only the filters are applied. Ordering is not: the grid sorts on the view, and the figures above
 * the list have no use for an order at all. What that costs is the export coming back in whatever
 * order the database gives rather than the one the column headers were last clicked in - which is
 * a question of which rows come first, never of which rows are there.
 */
final class FilteredSurveys
{
    public const GRID = 'cyfrowe_nps_admin_survey';

    public function __construct(
        private readonly GridProviderInterface $gridProvider,
        private readonly DataSourceProviderInterface $dataSourceProvider,
        private readonly FiltersApplicatorInterface $filtersApplicator,
    ) {
    }

    /**
     * For a page that already has the grid in hand, answering for the view it was given rather
     * than for whatever the grid is named.
     */
    public function forGridView(GridViewInterface $gridView): QueryBuilder
    {
        return $this->build($gridView->getDefinition(), $gridView->getParameters());
    }

    /**
     * The same view with only the filters the callback keeps, defaults included - for a figure
     * that some filters would distort, to tell whether those filters narrowed anything.
     *
     * @param callable(Filter): bool $keep
     */
    public function forGridViewFilteredOnlyBy(GridViewInterface $gridView, callable $keep): QueryBuilder
    {
        $definition = clone $gridView->getDefinition();

        foreach ($definition->getFilters() as $filter) {
            if (!$keep($filter)) {
                $definition->removeFilter($filter->getName());
            }
        }

        return $this->build($definition, $gridView->getParameters());
    }

    /**
     * For a request of its own - the export - carrying the criteria in its query string.
     */
    public function forCriteria(Parameters $parameters): QueryBuilder
    {
        return $this->build($this->gridProvider->get(self::GRID), $parameters);
    }

    private function build(Grid $definition, Parameters $parameters): QueryBuilder
    {
        $dataSource = $this->dataSourceProvider->getDataSource($definition, $parameters);
        $this->filtersApplicator->apply($dataSource, $definition, $parameters);

        assert($dataSource instanceof DataSource);

        return clone $dataSource->getQueryBuilder();
    }
}
