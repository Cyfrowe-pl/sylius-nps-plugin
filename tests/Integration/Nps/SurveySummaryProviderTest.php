<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Nps;

use Cyfrowe\SyliusNpsPlugin\Entity\HandlingStatus;
use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Nps\SurveySummary;
use Cyfrowe\SyliusNpsPlugin\Nps\SurveySummaryProvider;
use Doctrine\DBAL\Logging\DebugStack;
use Sylius\Component\Grid\Definition\Filter;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\Parameters;
use Sylius\Component\Grid\Provider\GridProviderInterface;
use Sylius\Component\Grid\View\GridView;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class SurveySummaryProviderTest extends IntegrationTestCase
{
    private const GRID = 'cyfrowe_nps_admin_survey';

    private SurveySummaryProvider $provider;

    private GridProviderInterface $gridProvider;

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = self::getContainer()->get('cyfrowe_nps.survey_summary_provider');
        assert($provider instanceof SurveySummaryProvider);

        $gridProvider = self::getContainer()->get('sylius.grid.provider');
        assert($gridProvider instanceof GridProviderInterface);

        $this->provider = $provider;
        $this->gridProvider = $gridProvider;
        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testCountsEveryBandAndTheIndexTheyAddUpTo(): void
    {
        // Both edges of every band, 8 included: without it a threshold moved by one would leave
        // these counts untouched and the aggregation would not notice.
        foreach ([0, 3, 6, 7, 8, 9, 10] as $score) {
            $this->answer($score);
        }
        $this->entityManager->flush();

        $summary = $this->summary();

        self::assertSame(7, $summary->answers);
        self::assertSame(3, $summary->countOf(ScoreCategory::Detractor));
        self::assertSame(2, $summary->countOf(ScoreCategory::Passive));
        self::assertSame(2, $summary->countOf(ScoreCategory::Promoter));
        self::assertSame(-14, $summary->index);
        self::assertSame(6.1, $summary->averageScore);
    }

    public function testLeavesTheIndexUndefinedWhenNothingHasBeenAnswered(): void
    {
        $summary = $this->summary();

        self::assertNull($summary->index, 'Zero is a real index - as many promoters as detractors - so an empty set must not read like one.');
        self::assertSame(0, $summary->answers);
        self::assertNull($summary->averageScore);
        self::assertSame(0.0, $summary->shareOf(ScoreCategory::Promoter));
    }

    public function testASurveyWithoutAnAnswerCountsAsSentAndNothingElse(): void
    {
        $answered = $this->answer(10);
        $answered->setSentAt(new \DateTimeImmutable('2026-09-01 10:00:00'));

        $unanswered = $this->factory->createSurvey();
        $unanswered->setSentAt(new \DateTimeImmutable('2026-09-01 10:00:00'));

        $this->entityManager->flush();

        $summary = $this->summary();

        self::assertSame(2, $summary->sent);
        self::assertSame(1, $summary->clicked);
        self::assertSame(1, $summary->answers);
        self::assertSame(100, $summary->index, 'An unanswered survey must not drag the index down.');
        self::assertSame(10.0, $summary->averageScore);
    }

    public function testTheFiguresFollowTheFiltersOfTheList(): void
    {
        $this->answer(10)->setChannelCode('WANTED');
        $this->answer(0)->setChannelCode('OTHER');
        $this->entityManager->flush();

        $summary = $this->summary(['channelCode' => 'WANTED']);

        self::assertSame(1, $summary->answers);
        self::assertSame(100, $summary->index);
    }

    public function testTheFiguresAnswerForOneShippingMethodOnItsOwn(): void
    {
        $this->answer(10)->setShippingMethodName('Pickup point');
        $this->answer(9)->setShippingMethodName('Pickup point');
        $this->answer(0)->setShippingMethodName('Express courier');
        $this->entityManager->flush();

        $summary = $this->summary(['shippingMethodName' => 'Pickup point']);

        self::assertSame(2, $summary->answers);
        self::assertSame(100, $summary->index, 'A shop that models pickup points as shipping methods reports on one point this way.');
    }

    public function testSpreadsTheAnswersOverTheWholeScale(): void
    {
        $this->answer(10);
        $this->answer(10);
        $this->answer(4);
        $this->entityManager->flush();

        $distribution = $this->summary()->scoreDistribution;

        self::assertCount(11, $distribution, 'Every score from 0 to 10 belongs on the chart, including the unused ones.');
        self::assertSame(2, $distribution[10]);
        self::assertSame(1, $distribution[4]);
        self::assertSame(0, $distribution[7]);
    }

    public function testReadsEveryFigureWithOneQuery(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->answer($i % 11);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stack = new DebugStack();
        $this->entityManager->getConnection()->getConfiguration()->setSQLLogger($stack);

        $this->summary();

        self::assertCount(1, $stack->queries, 'The figures are aggregated by the database, not counted in PHP.');
        self::assertSame(0, $this->entityManager->getUnitOfWork()->size(), 'No record may be hydrated to produce the figures.');
    }

    public function testFiltersThatOnlyChooseWhichSurveysWentOutCostNoSecondQuery(): void
    {
        $this->sentOn('2026-10-05 10:00:00');
        $this->entityManager->flush();

        $stack = new DebugStack();
        $this->entityManager->getConnection()->getConfiguration()->setSQLLogger($stack);

        $summary = $this->summary(['sentAt' => ['from' => ['date' => '2026-10-01']], 'channelCode' => 'TEST']);

        self::assertCount(1, $stack->queries);
        self::assertFalse($summary->ratesWithheld);
        self::assertSame(0.0, $summary->responseRate);
    }

    public function testTheRatesAreWithheldByADefaultTheGridSetsOnAFilter(): void
    {
        $this->sentOn('2026-10-05 10:00:00');
        $this->sentOn('2026-10-05 10:00:00')->setHandlingStatus(HandlingStatus::Done);
        $this->entityManager->flush();

        $grid = $this->gridWithFilter('handlingStatus', static fn (Filter $filter) => $filter->setCriteria([HandlingStatus::Done->value]));

        $summary = $this->provider->forGridView(new GridView([], $grid, new Parameters()));

        self::assertSame(1, $summary->sent, 'The default narrows the list without a word in the address.');
        self::assertTrue($summary->ratesWithheld);
        self::assertNull($summary->responseRate);
    }

    public function testAFilterTheShopMarksAsKeepingTheRatesKeepsThem(): void
    {
        $this->sentOn('2026-10-05 10:00:00');
        $this->sentOn('2026-10-05 10:00:00')->setHandlingStatus(HandlingStatus::Done);
        $this->entityManager->flush();

        $grid = $this->gridWithFilter('handlingStatus', static fn (Filter $filter) => $filter->setOptions([
            SurveySummaryProvider::KEEPS_RATES_OPTION => true,
        ]));

        $summary = $this->provider->forGridView(new GridView([], $grid, new Parameters([
            'criteria' => ['handlingStatus' => [HandlingStatus::Done->value]],
        ])));

        self::assertSame(1, $summary->sent);
        self::assertFalse($summary->ratesWithheld);
        self::assertSame(0.0, $summary->responseRate);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function summary(array $criteria = []): SurveySummary
    {
        $grid = $this->gridProvider->get(self::GRID);

        return $this->provider->forGridView(new GridView([], $grid, new Parameters(['criteria' => $criteria])));
    }

    /**
     * A copy, so the definition the rest of the suite reads stays as configured.
     *
     * @param callable(Filter): void $change
     */
    private function gridWithFilter(string $name, callable $change): Grid
    {
        $grid = clone $this->gridProvider->get(self::GRID);
        $filter = clone $grid->getFilter($name);
        $change($filter);
        $grid->setFilter($filter);

        return $grid;
    }

    private function sentOn(string $sentAt): Survey
    {
        $survey = $this->factory->createSurvey(status: SurveyStatus::Sent);
        $survey->setSentAt(new \DateTimeImmutable($sentAt));

        return $survey;
    }

    private function answer(int $score): Survey
    {
        $survey = $this->factory->createSurvey();
        $survey->setScore($score);
        $survey->setScoreSetAt(new \DateTimeImmutable('2026-09-02 10:00:00'));

        return $survey;
    }
}
