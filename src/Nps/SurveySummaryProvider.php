<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Nps;

use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;
use Cyfrowe\SyliusNpsPlugin\Grid\FilteredSurveys;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Grid\Definition\Filter;
use Sylius\Component\Grid\View\GridViewInterface;

/**
 * The figures above the admin list, for whatever the list is currently showing.
 */
final class SurveySummaryProvider
{
    /**
     * The grid filter option marking a filter that chooses which surveys went out rather than how
     * they were answered.
     */
    public const KEEPS_RATES_OPTION = 'keeps_rates';

    private const LOWEST_SCORE = 0;

    private const HIGHEST_SCORE = 10;

    public function __construct(
        private readonly FilteredSurveys $filteredSurveys,
        private readonly NpsCalculator $calculator,
    ) {
    }

    public function forGridView(GridViewInterface $gridView): SurveySummary
    {
        $queryBuilder = $this->filteredSurveys->forGridView($gridView);
        $population = $this->filteredSurveys->forGridViewFilteredOnlyBy($gridView, $this->keepsRates(...));
        $narrowedByOtherFilters = $queryBuilder->getDQL() !== $population->getDQL();
        $alias = $queryBuilder->getRootAliases()[0];

        /** @var array<string, mixed> $row */
        $row = $queryBuilder
            ->select($this->aggregates($alias))
            ->getQuery()
            // Sylius' OrderByIdentifierSqlWalker would append ORDER BY <root>.id, which MySQL
            // refuses next to aggregates with no GROUP BY.
            ->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [])
            ->getSingleResult()
        ;

        $ratesWithheld = $narrowedByOtherFilters && $this->countSent($population) !== (int) $row['sent'];

        return $this->summarise($row, $ratesWithheld);
    }

    private function keepsRates(Filter $filter): bool
    {
        return true === ($filter->getOptions()[self::KEEPS_RATES_OPTION] ?? false);
    }

    /**
     * The rates read sent surveys alone, so they stay true to the population for as long as the
     * other filters leave every sent survey in place - whichever filters those are and however
     * their values are shaped. Only a filter that left one out makes the rate the filter's own:
     * 100% for the answered ones, 0% for the rest.
     *
     * Whether to count at all is decided by comparing the two queries, which holds because Sylius
     * names its parameters after the fields. A filter that adds a join or a condition even when it
     * is left empty makes them differ on every render: the answer stays right, the count is wasted.
     */
    private function countSent(QueryBuilder $population): int
    {
        $alias = $population->getRootAliases()[0];

        return (int) $population
            ->select(sprintf('COUNT(%s.id)', $alias))
            ->andWhere(sprintf('%s.sentAt IS NOT NULL', $alias))
            ->getQuery()
            ->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [])
            ->getSingleScalarResult()
        ;
    }

    /**
     * Every figure in one pass over the records. Surveys that carry no score still count towards
     * how many were sent, and towards nothing else - an unanswered survey must not drag the index
     * or the average down.
     *
     * @return list<string>
     */
    private function aggregates(string $alias): array
    {
        $aggregates = [
            sprintf('COUNT(%s.score) AS answers', $alias),
            sprintf('AVG(%s.score) AS averageScore', $alias),
            $this->countWhere($alias, 'comment IS NOT NULL', 'withComment'),
            $this->countWhere($alias, 'sentAt IS NOT NULL', 'sent'),
            $this->countWhere($alias, 'scoreSetAt IS NOT NULL', 'clicked'),
            $this->countWhere($alias, 'completedAt IS NOT NULL', 'completed'),
        ];

        foreach (ScoreCategory::cases() as $category) {
            [$from, $to] = $category->scoreRange();

            $aggregates[] = $this->countWhere(
                $alias,
                sprintf('score BETWEEN %d AND %d', $from, $to),
                'category_' . $category->value,
            );
        }

        for ($score = self::LOWEST_SCORE; $score <= self::HIGHEST_SCORE; ++$score) {
            $aggregates[] = $this->countWhere($alias, sprintf('score = %d', $score), 'score_' . $score);
        }

        return $aggregates;
    }

    private function countWhere(string $alias, string $condition, string $as): string
    {
        return sprintf('SUM(CASE WHEN %s.%s THEN 1 ELSE 0 END) AS %s', $alias, $condition, $as);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function summarise(array $row, bool $ratesWithheld): SurveySummary
    {
        $answers = (int) $row['answers'];

        $countByCategory = [];
        $shareByCategory = [];

        foreach (ScoreCategory::cases() as $category) {
            $count = (int) $row['category_' . $category->value];

            $countByCategory[$category->value] = $count;
            $shareByCategory[$category->value] = $this->calculator->share($count, $answers);
        }

        $scoreDistribution = [];

        for ($score = self::LOWEST_SCORE; $score <= self::HIGHEST_SCORE; ++$score) {
            $scoreDistribution[$score] = (int) $row['score_' . $score];
        }

        $withComment = (int) $row['withComment'];
        $average = $row['averageScore'];
        $sent = (int) $row['sent'];
        $clicked = (int) $row['clicked'];
        $completed = (int) $row['completed'];

        return new SurveySummary(
            index: $this->calculator->index(
                $countByCategory[ScoreCategory::Promoter->value],
                $countByCategory[ScoreCategory::Detractor->value],
                $answers,
            ),
            answers: $answers,
            averageScore: null === $average ? null : round((float) $average, 1),
            countByCategory: $countByCategory,
            shareByCategory: $shareByCategory,
            scoreDistribution: $scoreDistribution,
            withComment: $withComment,
            commentShare: $this->calculator->share($withComment, $answers),
            sent: $sent,
            clicked: $clicked,
            completed: $completed,
            responseRate: $ratesWithheld ? null : $this->calculator->rate($clicked, $sent),
            completionRate: $ratesWithheld ? null : $this->calculator->rate($completed, $sent),
            ratesWithheld: $ratesWithheld,
        );
    }
}
