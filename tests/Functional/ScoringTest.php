<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Functional;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class ScoringTest extends FunctionalTestCase
{
    public function testClickingANumberSavesTheScoreAndRedirectsToTheForm(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 9));

        self::assertResponseRedirects(
            $this->surveyUrl($survey),
            null,
            'The score must not travel on to the form in the address - the form reads it from the record.',
        );

        $survey = $this->reload($survey);
        self::assertSame(9, $survey->getScore());
        self::assertSame(SurveyStatus::Scored, $survey->getStatus());
        self::assertNotNull($survey->getScoreSetAt());
    }

    public function testTheSurveyPageOpensAfterScoring(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 4));
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
    }

    public function testReopeningTheSurveyPageDoesNotScoreItAgain(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 7));
        $scoredAt = $this->reload($survey)->getScoreSetAt()?->format('Y-m-d H:i:s');

        $this->client->request('GET', $this->surveyUrl($survey));
        $this->client->request('GET', $this->surveyUrl($survey));

        $survey = $this->reload($survey);
        self::assertSame(7, $survey->getScore());
        self::assertSame($scoredAt, $survey->getScoreSetAt()?->format('Y-m-d H:i:s'), 'Opening the page must not re-score the survey.');
    }

    public function testAnExpiredLinkSavesNothing(): void
    {
        $survey = $this->survey();
        $survey->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();

        $this->client->request('GET', $this->scoreUrl($survey, 10));

        self::assertResponseIsSuccessful();
        $survey = $this->reload($survey);
        self::assertNull($survey->getScore(), 'An expired link must not accept a score.');
    }

    public function testAnAnsweredSurveyCannotBeScoredAgain(): void
    {
        $survey = $this->survey();
        $survey->setScore(8);
        $survey->setCompletedAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        $this->client->request('GET', $this->scoreUrl($survey, 2));

        self::assertResponseIsSuccessful();
        $survey = $this->reload($survey);
        self::assertSame(8, $survey->getScore(), 'A finished survey keeps the score the customer left.');
    }

    public function testAnUnknownTokenShowsAPageRatherThanAnError(): void
    {
        $this->client->request('GET', '/pl_PL/nps/' . bin2hex(random_bytes(8)) . '/9');

        self::assertResponseIsSuccessful();
    }

    public function testAScoreOutsideTheScaleIsIgnored(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 42));

        self::assertResponseRedirects($this->surveyUrl($survey));
        $survey = $this->reload($survey);
        self::assertNull($survey->getScore());
    }

    public function testEverySurveyResponseAsksToStayOutOfSearchResults(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 9));

        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
    }

    public function testTheSurveyPageAlsoAsksRobotsToStayAwayInTheMarkup(): void
    {
        $survey = $this->survey();

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        self::assertCount(
            1,
            $crawler->filter('meta[name="robots"][content*="noindex"]'),
            'The header alone is not enough - a page saved or forwarded keeps only its markup.',
        );
    }

    /**
     * Symfony resets the container between requests, which detaches everything the test was
     * holding on to. Read the record back rather than trusting the old reference.
     */
    private function reload(Survey $survey): Survey
    {
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Survey::class, $survey->getId());
        assert($reloaded instanceof Survey);

        return $reloaded;
    }

    private function survey(): Survey
    {
        $factory = new SurveyFactory($this->entityManager);
        $survey = $factory->createSurvey();
        $survey->setToken(bin2hex(random_bytes(8)));
        $this->entityManager->flush();

        return $survey;
    }

    private function scoreUrl(Survey $survey, int $score): string
    {
        return sprintf('%s/%d', $this->surveyUrl($survey), $score);
    }

    private function surveyUrl(Survey $survey): string
    {
        return sprintf('/%s/nps/%s', $survey->getLocaleCode() ?? 'en_US', (string) $survey->getToken());
    }
}
