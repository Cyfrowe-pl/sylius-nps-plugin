<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Functional;

use Cyfrowe\SyliusNpsPlugin\Entity\HandlingStatus;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Sylius\Component\Core\Model\Customer;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class AdminResponseListTest extends FunctionalTestCase
{
    private const PATH = '/admin/nps/responses/';

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SurveyFactory($this->entityManager);
        $this->logIn();
    }

    public function testTheListRendersBeforeAnyAnswerHasBeenGiven(): void
    {
        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('.sylius-grid-table-wrapper tbody tr'),
            'The filters and the figures both read from the answers, so an empty shop must still render.',
        );
    }

    public function testTheListShowsTheAnswersThatHaveBeenGiven(): void
    {
        $this->createAnswer('ORD-DETRACTOR', 3);
        $this->createAnswer('ORD-PROMOTER', 10);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-DETRACTOR', $crawler->html());
        self::assertStringContainsString('ORD-PROMOTER', $crawler->html());
    }

    public function testTheCategoryFilterNarrowsTheListToOneBand(): void
    {
        $this->createAnswer('ORD-SIX', 6);
        $this->createAnswer('ORD-SEVEN', 7);
        $this->createAnswer('ORD-NINE', 9);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, ['criteria' => ['category' => 'detractor']]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-SIX', $crawler->html());
        self::assertStringNotContainsString('ORD-SEVEN', $crawler->html());
        self::assertStringNotContainsString('ORD-NINE', $crawler->html());
    }

    public function testTheScoreFilterNarrowsTheListToARange(): void
    {
        $this->createAnswer('ORD-FOUR', 4);
        $this->createAnswer('ORD-EIGHT', 8);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, [
            'criteria' => ['score' => ['greaterThan' => 7, 'lessThan' => 10]],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-EIGHT', $crawler->html());
        self::assertStringNotContainsString('ORD-FOUR', $crawler->html());
    }

    public function testTheSearchFilterFindsAnAnswerByTheCustomerEmail(): void
    {
        $customer = new Customer();
        $customer->setEmail('wanted@example.com');
        $this->entityManager->persist($customer);

        $this->createAnswer('ORD-WANTED', 5)->setCustomer($customer);
        $this->createAnswer('ORD-OTHER', 5);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, [
            'criteria' => ['search' => ['value' => 'wanted@example.com']],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-WANTED', $crawler->html());
        self::assertStringNotContainsString('ORD-OTHER', $crawler->html());
    }

    public function testTheChannelFilterOffersTheCodesTheAnswersCarry(): void
    {
        $this->createAnswer('ORD-ONE', 5)->setChannelCode('A_CHANNEL_WITH_ANSWERS');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('option:contains("A_CHANNEL_WITH_ANSWERS")'),
            'The filter reads its choices from the answers, so a channel that has one must be offered.',
        );
    }

    public function testTheChannelFilterNarrowsTheListToOneChannel(): void
    {
        $this->createAnswer('ORD-HERE', 5)->setChannelCode('WANTED_CHANNEL');
        $this->createAnswer('ORD-ELSEWHERE', 5)->setChannelCode('OTHER_CHANNEL');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, [
            'criteria' => ['channelCode' => 'WANTED_CHANNEL'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-HERE', $crawler->html());
        self::assertStringNotContainsString('ORD-ELSEWHERE', $crawler->html());
    }

    public function testTheSummarySitsAboveTheList(): void
    {
        $this->createAnswer('ORD-A', 10);
        $this->createAnswer('ORD-B', 9);
        $this->createAnswer('ORD-C', 3);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertSame('33', $crawler->filter('.statistic .value')->first()->text());
    }

    public function testTheSummaryIsRecalculatedForTheFiltersInTheAddress(): void
    {
        $this->createAnswer('ORD-A', 10);
        $this->createAnswer('ORD-B', 3);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, ['criteria' => ['category' => 'detractor']]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            '-100',
            $crawler->filter('.statistic .value')->first()->text(),
            'The figures answer for what the list is showing, not for everything ever answered.',
        );
    }

    public function testTheHandlingFilterSeparatesWhatHasBeenDealtWith(): void
    {
        $this->createAnswer('ORD-UNTOUCHED', 5);
        $this->createAnswer('ORD-SETTLED', 5)->setHandlingStatus(HandlingStatus::Done);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, [
            'criteria' => ['handlingStatus' => HandlingStatus::New->value],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-UNTOUCHED', $crawler->html());
        self::assertStringNotContainsString('ORD-SETTLED', $crawler->html());
    }

    public function testTheHandlingFilterNamesTheStatusesAsTheColumnDoes(): void
    {
        $this->createAnswer('ORD-IN-PROGRESS', 5)->setHandlingStatus(HandlingStatus::InProgress);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['New', 'In progress', 'Handled'],
            $crawler->filter('select[name="criteria[handlingStatus][]"] option')->each(static fn (Crawler $option): string => trim($option->text())),
        );
        self::assertSame('In progress', trim($crawler->filter('tbody .ui.label.yellow')->text()));
        self::assertSelectorTextContains('label[for="criteria_handlingStatus"]', 'Handling status');
    }

    public function testTheHandlingFilterAcceptsSeveralStatusesAtOnce(): void
    {
        $this->createAnswer('ORD-UNTOUCHED', 5);
        $this->createAnswer('ORD-UNDERWAY', 5)->setHandlingStatus(HandlingStatus::InProgress);
        $this->createAnswer('ORD-SETTLED', 5)->setHandlingStatus(HandlingStatus::Done);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, [
            'criteria' => ['handlingStatus' => [HandlingStatus::New->value, HandlingStatus::InProgress->value]],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-UNTOUCHED', $crawler->html());
        self::assertStringContainsString('ORD-UNDERWAY', $crawler->html());
        self::assertStringNotContainsString('ORD-SETTLED', $crawler->html());
    }

    public function testTheListShowsTheStatusOfEverySurvey(): void
    {
        $this->factory->createSurvey(status: SurveyStatus::Skipped)->setOrderNumber('ORD-SKIPPED');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertSame('Skipped', trim($crawler->filter('tbody .ui.label.orange')->text()));
    }

    public function testTheStatusFilterAcceptsSeveralStatusesAtOnce(): void
    {
        $this->factory->createSurvey(status: SurveyStatus::Sent)->setOrderNumber('ORD-SENT');
        $this->createAnswer('ORD-SCORED', 9)->setStatus(SurveyStatus::Scored);
        $this->factory->createSurvey(status: SurveyStatus::Skipped)->setOrderNumber('ORD-SKIPPED');
        $this->factory->createSurvey()->setOrderNumber('ORD-PENDING');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, [
            'criteria' => ['status' => [SurveyStatus::Sent->value, SurveyStatus::Scored->value]],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-SENT', $crawler->html());
        self::assertStringContainsString('ORD-SCORED', $crawler->html());
        self::assertStringNotContainsString('ORD-SKIPPED', $crawler->html());
        self::assertStringNotContainsString('ORD-PENDING', $crawler->html());
    }

    public function testTheSendingDateFilterKeepsTheSurveysNobodyAnswered(): void
    {
        $this->sent('ORD-IGNORED', '2026-10-05 10:00:00');
        $this->sent('ORD-ANSWERED', '2026-10-06 10:00:00')->setScoreSetAt(new \DateTimeImmutable('2026-10-20 10:00:00'));
        $this->sent('ORD-SEPTEMBER', '2026-09-15 10:00:00');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, [
            'criteria' => ['sentAt' => ['from' => ['date' => '2026-10-01'], 'to' => ['date' => '2026-10-31']]],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-IGNORED', $crawler->html());
        self::assertStringContainsString('ORD-ANSWERED', $crawler->html());
        self::assertStringNotContainsString('ORD-SEPTEMBER', $crawler->html());
    }

    public function testTheRatesAreTheShareOfTheSurveysSent(): void
    {
        $this->sent('ORD-IGNORED', '2026-10-05 10:00:00');
        $this->sent('ORD-SCORED', '2026-10-05 10:00:00')->setScoreSetAt(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $completed = $this->sent('ORD-COMPLETED', '2026-10-05 10:00:00');
        $completed->setScoreSetAt(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $completed->setCompletedAt(new \DateTimeImmutable('2026-10-06 10:05:00'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, [
            'criteria' => ['sentAt' => ['from' => ['date' => '2026-10-01'], 'to' => ['date' => '2026-10-31']]],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['66.7%', '33.3%'], $crawler->filter('.nps-rates .value')->each(
            static fn (Crawler $value): string => trim($value->text()),
        ));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function filtersPickingByTheAnswer(): iterable
    {
        yield 'scoring date' => [['scoreSetAt' => ['from' => ['date' => '2026-10-01']]]];
        yield 'score range' => [['score' => ['greaterThan' => 0]]];
        yield 'band' => [['category' => 'promoter']];
        yield 'answered' => [['answered' => 'absent']];
        yield 'comment' => [['comment' => 'present']];
        yield 'survey status' => [['status' => ['sent']]];
        yield 'handling status' => [['handlingStatus' => ['done']]];
    }

    /**
     * @dataProvider filtersPickingByTheAnswer
     *
     * @param array<string, mixed> $criteria
     */
    public function testTheRatesAreWithheldWhileAFilterPicksSurveysByTheirAnswer(array $criteria): void
    {
        $this->sent('ORD-IGNORED', '2026-10-05 10:00:00');
        $this->scoredAfterSending('ORD-SCORED', 9);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, ['criteria' => $criteria]);

        self::assertResponseIsSuccessful();
        self::assertSame(['-', '-'], $crawler->filter('.nps-rates .value')->each(
            static fn (Crawler $value): string => trim($value->text()),
        ), 'A rate over a set picked by its answers is decided by the filter, not by the customers.');
        self::assertCount(1, $crawler->filter('.nps-rates-hint'));
    }

    public function testTheRatesStayWhileAStatusFilterKeepsEverySurveySent(): void
    {
        $this->sent('ORD-IGNORED', '2026-10-05 10:00:00');
        $this->scoredAfterSending('ORD-SCORED', 9);
        $this->factory->createSurvey(status: SurveyStatus::Skipped)->setOrderNumber('ORD-SKIPPED');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, ['criteria' => [
            'status' => [SurveyStatus::Sent->value, SurveyStatus::Scored->value, SurveyStatus::Completed->value],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('ORD-SKIPPED', $crawler->html());
        self::assertSame(['50%', '0%'], $crawler->filter('.nps-rates .value')->each(
            static fn (Crawler $value): string => trim($value->text()),
        ), 'Leaving out surveys never sent takes nothing from the rates.');
    }

    public function testTheRatesStayWhileTheFiltersOnlyChooseWhichSurveysWentOut(): void
    {
        $this->sent('ORD-IGNORED', '2026-10-05 10:00:00')->setChannelCode('WANTED');
        $this->sent('ORD-SCORED', '2026-10-05 10:00:00')->setScoreSetAt(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, ['criteria' => [
            'sentAt' => ['from' => ['date' => '2026-10-01']],
            'channelCode' => 'TEST',
            'search' => ['type' => 'contains', 'value' => 'ORD-'],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['100%', '0%'], $crawler->filter('.nps-rates .value')->each(
            static fn (Crawler $value): string => trim($value->text()),
        ));
        self::assertCount(0, $crawler->filter('.nps-rates-hint'));
    }

    public function testSubmittingTheFiltersUntouchedKeepsTheRates(): void
    {
        $this->sent('ORD-IGNORED', '2026-10-05 10:00:00');
        $this->sent('ORD-SCORED', '2026-10-05 10:00:00')->setScoreSetAt(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH);
        $crawler = $this->client->submit($crawler->selectButton('Filter')->form());

        self::assertSame(['50%', '0%'], $crawler->filter('.nps-rates .value')->each(
            static fn (Crawler $value): string => trim($value->text()),
        ), 'Every field of an untouched form arrives empty, and none of them may count as a filter in use.');
    }

    public function testTheListCanBeSortedByEveryColumn(): void
    {
        $this->createAnswer('ORD-ONE', 5);
        $this->entityManager->flush();

        $columns = ['scoreSetAt', 'score', 'category', 'customerEmail', 'orderNumber', 'shippingMethodName', 'status', 'handlingStatus', 'comment'];

        foreach ($columns as $column) {
            $this->client->request('GET', self::PATH, ['sorting' => [$column => 'asc']]);

            self::assertResponseIsSuccessful(sprintf('The list must be sortable by "%s".', $column));
        }
    }

    public function testSortingReachesThroughToTheCustomer(): void
    {
        $this->createAnswer('ORD-LAST', 5)->setCustomer($this->customer('zoe@example.com'));
        $this->createAnswer('ORD-FIRST', 5)->setCustomer($this->customer('ann@example.com'));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, ['sorting' => ['customerEmail' => 'asc']]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'ORD-FIRST',
            $crawler->filter('.sylius-grid-table-wrapper tbody tr')->first()->html(),
            'The column reads through a join, so ordering by it has to join as well.',
        );
    }

    private function customer(string $email): Customer
    {
        $customer = new Customer();
        $customer->setEmail($email);

        $this->entityManager->persist($customer);

        return $customer;
    }

    public function testSubmittingTheFiltersUntouchedNarrowsNothing(): void
    {
        $this->createAnswer('ORD-SPOKE-UP', 9)->setComment('Everything arrived on time.');

        $silent = $this->factory->createSurvey();
        $silent->setOrderNumber('ORD-SAID-NOTHING');

        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH);
        $this->client->submit($crawler->selectButton('Filter')->form());

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('ORD-SPOKE-UP', $html);
        self::assertStringContainsString(
            'ORD-SAID-NOTHING',
            $html,
            'Pressing filter without choosing anything must not quietly hide half the list.',
        );
    }

    public function testTheCommentFilterShowsEitherSideOnDemand(): void
    {
        $this->createAnswer('ORD-COMMENTED', 9)->setComment('A sentence worth reading.');
        $this->createAnswer('ORD-SILENT', 9);
        $this->entityManager->flush();

        $withComment = $this->client->request('GET', self::PATH, ['criteria' => ['comment' => 'present']]);
        self::assertStringContainsString('ORD-COMMENTED', $withComment->html());
        self::assertStringNotContainsString('ORD-SILENT', $withComment->html());

        $withoutComment = $this->client->request('GET', self::PATH, ['criteria' => ['comment' => 'absent']]);
        self::assertStringContainsString('ORD-SILENT', $withoutComment->html());
        self::assertStringNotContainsString('ORD-COMMENTED', $withoutComment->html());
    }

    public function testTheScoreFilterFindsTheSurveysNobodyAnswered(): void
    {
        $this->createAnswer('ORD-ANSWERED', 9);
        $this->factory->createSurvey()->setOrderNumber('ORD-IGNORED');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH, ['criteria' => ['answered' => 'absent']]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ORD-IGNORED', $crawler->html());
        self::assertStringNotContainsString('ORD-ANSWERED', $crawler->html());
    }

    public function testTheDistributionDrawsABarForEveryScoreThatWasGiven(): void
    {
        $this->createAnswer('ORD-A', 10);
        $this->createAnswer('ORD-B', 10);
        $this->createAnswer('ORD-C', 4);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::PATH);

        $heights = $crawler->filter('.nps-distribution__bar')->each(
            static fn ($bar) => (string) $bar->attr('style'),
        );

        self::assertCount(11, $heights);
        self::assertSame('height: 80px', $heights[10], 'The busiest score fills the track.');
        self::assertSame('height: 40px', $heights[4], 'Half as many answers, half the height.');
        self::assertSame('height: 0px', $heights[7]);
    }

    private function scoredAfterSending(string $orderNumber, int $score): Survey
    {
        $survey = $this->sent($orderNumber, '2026-10-05 10:00:00');
        $survey->setStatus(SurveyStatus::Scored);
        $survey->setScore($score);
        $survey->setScoreSetAt(new \DateTimeImmutable('2026-10-06 10:00:00'));

        return $survey;
    }

    private function sent(string $orderNumber, string $sentAt): Survey
    {
        $survey = $this->factory->createSurvey(status: SurveyStatus::Sent);
        $survey->setOrderNumber($orderNumber);
        $survey->setSentAt(new \DateTimeImmutable($sentAt));

        return $survey;
    }

    private function createAnswer(string $orderNumber, int $score): Survey
    {
        $survey = $this->factory->createSurvey();
        $survey->setOrderNumber($orderNumber);
        $survey->setScore($score);
        $survey->setScoreSetAt(new \DateTimeImmutable('2026-09-01 10:00:00'));

        return $survey;
    }
}
