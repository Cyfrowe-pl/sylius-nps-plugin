<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Grid;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Grid\FilteredSurveys;
use Sylius\Component\Grid\Parameters;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class FilteredSurveysTest extends IntegrationTestCase
{
    private FilteredSurveys $filteredSurveys;

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $filteredSurveys = self::getContainer()->get('cyfrowe_nps.grid.filtered_surveys');
        assert($filteredSurveys instanceof FilteredSurveys);

        $this->filteredSurveys = $filteredSurveys;
        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testACriteriaOnlyRequestIsNarrowedByTheFiltersOfTheList(): void
    {
        $this->answer('WANTED');
        $this->answer('OTHER');
        $this->entityManager->flush();

        $found = $this->filteredSurveys
            ->forCriteria(new Parameters(['criteria' => ['channelCode' => 'WANTED']]))
            ->getQuery()
            ->getResult()
        ;

        self::assertCount(1, $found);
    }

    public function testWithoutCriteriaNothingIsNarrowed(): void
    {
        $this->answer('WANTED');
        $this->answer('OTHER');
        $this->entityManager->flush();

        $found = $this->filteredSurveys->forCriteria(new Parameters())->getQuery()->getResult();

        self::assertCount(2, $found);
    }

    public function testEveryFilterOfTheListReachesARequestOfItsOwn(): void
    {
        $answered = $this->answer('WANTED');
        $answered->setScore(9);
        $this->answer('WANTED');
        $this->entityManager->flush();

        // Whatever narrows the screen has to narrow the file, so a filter the export never
        // mentions by name still has to apply to it.
        $found = $this->filteredSurveys
            ->forCriteria(new Parameters(['criteria' => ['answered' => 'present']]))
            ->getQuery()
            ->getResult()
        ;

        self::assertCount(1, $found);
    }

    private function answer(string $channelCode): Survey
    {
        return $this->factory->createSurvey(channelCode: $channelCode);
    }
}
