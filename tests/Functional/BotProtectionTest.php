<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Functional;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class BotProtectionTest extends FunctionalTestCase
{
    public function testARequestAnnouncingItselfAsPrefetchScoresNothing(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 9), server: ['HTTP_PURPOSE' => 'prefetch']);

        $survey = $this->reload($survey);
        self::assertNull($survey->getScore(), 'A mail client following the link is not the customer answering.');
        self::assertTrue($survey->isBotSuspected());
    }

    public function testAKnownScannerSignatureScoresNothing(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 9), server: ['HTTP_USER_AGENT' => 'Mimecast Link Scanner']);

        $survey = $this->reload($survey);
        self::assertNull($survey->getScore());
        self::assertTrue($survey->isBotSuspected());
    }

    public function testAnOrdinaryBrowserIsLeftAlone(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 9), server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0']);

        $survey = $this->reload($survey);
        self::assertSame(9, $survey->getScore());
        self::assertFalse($survey->isBotSuspected());
    }

    public function testOpeningEveryNumberInTheEmailFlagsTheRecordAndScoresNothing(): void
    {
        $survey = $this->survey();

        foreach (range(0, 10) as $score) {
            $this->client->request('GET', $this->scoreUrl($survey, $score));
        }

        $survey = $this->reload($survey);
        self::assertNull($survey->getScore(), 'A scanner opening all eleven links must not leave a score behind.');
        self::assertTrue($survey->isBotSuspected());
    }

    public function testChangingYourMindOnceIsNotTreatedAsAScanner(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 2));
        $this->client->request('GET', $this->scoreUrl($survey, 8));

        $survey = $this->reload($survey);
        self::assertSame(8, $survey->getScore(), 'A customer correcting a misclick keeps their score.');
        self::assertFalse($survey->isBotSuspected());
    }

    public function testAnEnumeratedSurveyLeavesNoScorePreselected(): void
    {
        $survey = $this->survey();

        // A scanner works through the links one by one, so the first ones land before anything
        // recognises what is going on. Whatever they left has to go.
        foreach (range(0, 10) as $score) {
            $this->client->request('GET', $this->scoreUrl($survey, $score));
        }

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('input[type="radio"][name$="[score]"][checked]'),
            'A scanner must not leave a score preselected for the customer.',
        );
        self::assertCount(11, $crawler->filter('input[type="radio"][name$="[score]"]'));
    }

    public function testAScannerFollowingTheLinkLaterDoesNotTakeTheAnswerAway(): void
    {
        $survey = $this->survey();

        $this->client->request('GET', $this->scoreUrl($survey, 9));
        $this->client->request('GET', $this->scoreUrl($survey, 2), server: ['HTTP_PURPOSE' => 'prefetch']);

        $survey = $this->reload($survey);
        self::assertSame(9, $survey->getScore(), 'The customer answered; a scanner arriving afterwards changes nothing.');
        self::assertTrue($survey->isBotSuspected(), 'The visit is still worth recording.');
    }

    public function testAnEnumeratedSurveyStopsCountingTowardsTheIndex(): void
    {
        $survey = $this->survey();

        foreach (range(0, 10) as $score) {
            $this->client->request('GET', $this->scoreUrl($survey, $score));
        }

        $survey = $this->reload($survey);
        self::assertNull($survey->getScore());
        self::assertFalse(
            $survey->getStatus()->countsTowardsIndex(),
            'A record with no score must not sit in a state the index counts.',
        );
    }

    private function reload(Survey $survey): Survey
    {
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Survey::class, $survey->getId());
        assert($reloaded instanceof Survey);

        return $reloaded;
    }

    private function survey(): Survey
    {
        $survey = (new SurveyFactory($this->entityManager))->createSurvey();
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
        return sprintf('/%s/nps/%s', (string) $survey->getLocaleCode(), (string) $survey->getToken());
    }
}
