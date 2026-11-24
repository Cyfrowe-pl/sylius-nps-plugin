<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Functional;

use Cyfrowe\SyliusNpsPlugin\Entity\HandlingStatus;
use Cyfrowe\SyliusNpsPlugin\Entity\SkipReason;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class AdminResponseCardTest extends FunctionalTestCase
{
    private const LONG_COMMENT = 'The courier left the parcel with a neighbour without telling me, and it took three days and four phone calls before anybody could say where it had gone.';

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SurveyFactory($this->entityManager);
        $this->logIn();
    }

    public function testShowsTheWholeCommentTheListCutsShort(): void
    {
        $survey = $this->answer();
        $survey->setComment(self::LONG_COMMENT);
        $this->entityManager->flush();

        $list = $this->client->request('GET', '/admin/nps/responses/');
        self::assertStringNotContainsString(self::LONG_COMMENT, $list->html());

        $card = $this->client->request('GET', $this->cardPath($survey));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::LONG_COMMENT, $card->html());
    }

    public function testTellsWhyASurveyWasNeverSent(): void
    {
        $survey = $this->factory->createSurvey(status: SurveyStatus::Skipped);
        $survey->setSkipReason(SkipReason::Cooldown);
        $this->entityManager->flush();

        $card = $this->client->request('GET', $this->cardPath($survey));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Skipped', $card->filter('.ui.label.orange')->text());
        self::assertStringContainsString('A survey went to this customer recently', $card->html());
    }

    public function testGivesNoReasonForASurveyThatWasSent(): void
    {
        $survey = $this->answer();
        $survey->setStatus(SurveyStatus::Scored);
        $this->entityManager->flush();

        $card = $this->client->request('GET', $this->cardPath($survey));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Scored', $card->filter('.ui.label.teal')->text());
        self::assertStringNotContainsString('Reason for skipping', $card->html());
    }

    public function testLeadsToTheOrderItIsAbout(): void
    {
        $survey = $this->answer();
        $survey->setOrderTotal(12999);
        $survey->setCurrencyCode('EUR');
        $this->entityManager->flush();

        $order = $survey->getOrder();
        self::assertNotNull($order);

        $card = $this->client->request('GET', $this->cardPath($survey));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $card->filter(sprintf('a[href="/admin/orders/%d"]', $order->getId())));
    }

    public function testRecordsWhoDealtWithTheAnswer(): void
    {
        $survey = $this->answer();
        $this->entityManager->flush();
        $id = $survey->getId();

        $card = $this->client->request('GET', $this->cardPath($survey));
        $form = $card->selectButton('Save')->form();
        $form['cyfrowe_nps_survey_handling[handlingStatus]'] = HandlingStatus::Done->value;
        $form['cyfrowe_nps_survey_handling[internalNote]'] = 'Called the customer back.';

        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sylius-flash-message', 'The status and note have been saved.');

        $saved = $this->reload($id);
        self::assertSame(HandlingStatus::Done, $saved->getHandlingStatus());
        self::assertSame('Called the customer back.', $saved->getInternalNote());
        self::assertSame('tester', $saved->getHandledBy(), 'The person is taken from the session, not typed in.');
    }

    public function testAForgedRequestChangesNothing(): void
    {
        $survey = $this->answer();
        $this->entityManager->flush();
        $id = $survey->getId();

        $this->client->request('POST', sprintf('/admin/nps/responses/%d/handling', $id), [
            'cyfrowe_nps_survey_handling' => [
                'handlingStatus' => HandlingStatus::Done->value,
                'internalNote' => 'Filed by an outsider.',
            ],
        ]);

        $saved = $this->reload($id);
        self::assertSame(HandlingStatus::New, $saved->getHandlingStatus());
        self::assertNull($saved->getInternalNote());
    }

    public function testAnAnswerThatDoesNotExistIsNotFound(): void
    {
        $this->client->request('GET', '/admin/nps/responses/987654');

        self::assertResponseStatusCodeSame(404);
    }

    private function answer(): Survey
    {
        $survey = $this->factory->createSurvey();
        $survey->setScore(3);
        $survey->setScoreSetAt(new \DateTimeImmutable('2026-09-02 10:00:00'));

        return $survey;
    }

    private function cardPath(Survey $survey): string
    {
        return sprintf('/admin/nps/responses/%d', (int) $survey->getId());
    }

    private function reload(?int $id): Survey
    {
        $this->entityManager->clear();

        $repository = self::getContainer()->get('cyfrowe_nps.repository.survey');
        assert($repository instanceof SurveyRepositoryInterface);

        $survey = $repository->find($id);
        assert($survey instanceof Survey);

        return $survey;
    }
}
