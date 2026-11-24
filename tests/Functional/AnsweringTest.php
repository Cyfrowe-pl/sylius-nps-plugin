<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Functional;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class AnsweringTest extends FunctionalTestCase
{
    private const LOW_QUESTION = 'What went wrong for you?';

    private const HIGH_QUESTION = 'What did you like most?';

    public function testSendingTheFormFinishesTheSurvey(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));
        $form = $crawler->filter('form')->form();
        $form['cyfrowe_nps_survey_answer[comment]'] = 'Everything arrived on time.';
        $this->client->submit($form);

        self::assertResponseIsSuccessful();

        $survey = $this->reload($survey);
        self::assertSame(SurveyStatus::Completed, $survey->getStatus());
        self::assertNotNull($survey->getCompletedAt());
        self::assertSame('Everything arrived on time.', $survey->getComment());
    }

    public function testTheCommentIsOptional(): void
    {
        $survey = $this->scoredSurvey(10);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));
        $this->client->submit($crawler->filter('form')->form());

        self::assertResponseIsSuccessful();

        $survey = $this->reload($survey);
        self::assertSame(SurveyStatus::Completed, $survey->getStatus());
        self::assertNull($survey->getComment());
    }

    public function testTheScoreCanStillBeChangedOnTheForm(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));
        $form = $crawler->filter('form')->form();
        $form['cyfrowe_nps_survey_answer[score]'] = '3';
        $this->client->submit($form);

        $survey = $this->reload($survey);
        self::assertSame(3, $survey->getScore());
    }

    public function testAFinishedSurveyIsNoLongerEditable(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));
        $this->client->submit($crawler->filter('form')->form());

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form'), 'A finished survey shows a thank-you, not a form to fill in again.');
    }

    public function testTheThankYouPageShowsTheChannelsThankYouTextLineByLine(): void
    {
        $configuration = new Configuration();
        $configuration->setChannelCode('NPS_FUNCTIONAL');
        $configuration->getTranslation('pl_PL')->setThankYouText("Thank you for your feedback.\nSee you <b>soon</b>.");
        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        $crawler = $this->finishSurvey();

        self::assertSame(
            'Thank you for your feedback.<br>' . "\n" . 'See you &lt;b&gt;soon&lt;/b&gt;.',
            trim($crawler->filter('.ui.success.message')->html()),
        );
    }

    public function testTheThankYouPageFallsBackToTheDefaultTextWhenTheChannelHasNone(): void
    {
        $configuration = new Configuration();
        $configuration->setChannelCode('NPS_FUNCTIONAL');
        $configuration->getTranslation('pl_PL')->setThankYouText('');
        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        $crawler = $this->finishSurvey();

        self::assertSame('Dziękujemy za Twój czas', trim($crawler->filter('.ui.success.message')->text()));
    }

    public function testTheFormCarriesBothWordingsSoTheQuestionCanSwapWithoutAReload(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        $question = $crawler->filter('[data-question-low][data-question-high]');

        self::assertCount(1, $question);
        self::assertNotSame('', $question->attr('data-question-low'));
        self::assertNotSame('', $question->attr('data-question-high'));
    }

    public function testTheSurveyPageLoadsTheScriptThatSwapsTheQuestion(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        self::assertCount(
            1,
            $crawler->filter('script[src*="cyfrowesyliusnpsplugin/js/survey.js"]'),
            'The behaviour ships as a plain bundle asset, so no build step is needed to get it.',
        );
    }

    public function testTheSurveyPageLoadsTheStylesheetThatLaysOutTheScale(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        self::assertCount(
            1,
            $crawler->filter('link[rel="stylesheet"][href*="cyfrowesyliusnpsplugin/css/survey.css"]'),
            'The scale is laid out by a plain bundle stylesheet, so no build step is needed to get it.',
        );
    }

    public function testTheScaleIsARadioGroupOfElevenTilesWithTheSavedScoreChecked(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        $inputs = $crawler->filter('[role="radiogroup"] input[type="radio"][name="cyfrowe_nps_survey_answer[score]"]');

        self::assertCount(11, $inputs);
        self::assertSame(range(0, 10), array_map('intval', $inputs->each(static fn ($input) => $input->attr('value'))));
        self::assertCount(11, $crawler->filter('[role="radiogroup"] label.nps-survey__score'));

        foreach ($inputs->each(static fn ($input) => $input->attr('id')) as $id) {
            self::assertCount(1, $crawler->filter(sprintf('label[for="%s"]', $id)), 'Each tile is a label, so clicking it picks the score.');
        }

        $checked = $crawler->filter('[role="radiogroup"] input[type="radio"]:checked');

        self::assertCount(1, $checked);
        self::assertSame('9', $checked->attr('value'));
    }

    public function testTheCommentHelpIsTranslated(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        self::assertSame(
            'Prosimy nie podawać danych osobowych.',
            trim($crawler->filter('#cyfrowe_nps_survey_answer_comment_help')->text()),
        );
    }

    public function testAPostWithoutACsrfTokenIsRejected(): void
    {
        $survey = $this->scoredSurvey(9);

        $this->client->request('POST', $this->surveyUrl($survey), [
            'cyfrowe_nps_survey_answer' => ['score' => '5', 'comment' => 'forged'],
        ]);

        $survey = $this->reload($survey);
        self::assertNull($survey->getCompletedAt(), 'A form post without the token must not finish the survey.');
        self::assertSame(9, $survey->getScore());
    }

    public function testAnOverlongCommentIsRejected(): void
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));
        $form = $crawler->filter('form')->form();
        $form['cyfrowe_nps_survey_answer[comment]'] = str_repeat('a', 2001);
        $this->client->submit($form);

        $survey = $this->reload($survey);
        self::assertNull($survey->getCompletedAt(), 'Anyone holding a token could otherwise post without limit.');
    }

    /** @return iterable<string, array{int, string}> */
    public static function scoreProvider(): iterable
    {
        yield 'the bottom of the scale' => [0, self::LOW_QUESTION];
        yield 'the last detractor' => [6, self::LOW_QUESTION];
        yield 'a passive' => [8, self::LOW_QUESTION];
        yield 'the first promoter' => [9, self::HIGH_QUESTION];
        yield 'the top of the scale' => [10, self::HIGH_QUESTION];
    }

    /** @dataProvider scoreProvider */
    public function testTheFollowUpQuestionMatchesTheScore(int $score, string $expected): void
    {
        $this->configureChannelWithDistinctQuestions();
        $survey = $this->scoredSurvey($score);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));

        self::assertSame(
            $expected,
            trim($crawler->filter('[data-question-low][data-question-high]')->text()),
            sprintf('A score of %d has to be followed by the right question.', $score),
        );
    }

    private function configureChannelWithDistinctQuestions(): void
    {
        $configuration = new Configuration();
        $configuration->setChannelCode('NPS_FUNCTIONAL');

        $translation = $configuration->getTranslation('pl_PL');
        $translation->setQuestionLow(self::LOW_QUESTION);
        $translation->setQuestionHigh(self::HIGH_QUESTION);

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();
    }

    private function finishSurvey(): Crawler
    {
        $survey = $this->scoredSurvey(9);

        $crawler = $this->client->request('GET', $this->surveyUrl($survey));
        $this->client->submit($crawler->filter('form')->form());

        return $this->client->request('GET', $this->surveyUrl($survey));
    }

    private function scoredSurvey(int $score): Survey
    {
        $survey = (new SurveyFactory($this->entityManager))->createSurvey(channelCode: 'NPS_FUNCTIONAL');
        $survey->setToken(bin2hex(random_bytes(8)));
        $survey->setScore($score);
        $survey->setScoreSetAt(new \DateTimeImmutable('-2 minutes'));
        $survey->setStatus(SurveyStatus::Scored);
        $this->entityManager->flush();

        return $survey;
    }

    private function reload(Survey $survey): Survey
    {
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Survey::class, $survey->getId());
        assert($reloaded instanceof Survey);

        return $reloaded;
    }

    private function surveyUrl(Survey $survey): string
    {
        return sprintf('/%s/nps/%s', (string) $survey->getLocaleCode(), (string) $survey->getToken());
    }
}
