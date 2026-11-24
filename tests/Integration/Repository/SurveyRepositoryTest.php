<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Repository;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Doctrine\Persistence\Proxy;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class SurveyRepositoryTest extends IntegrationTestCase
{
    private SurveyRepositoryInterface $repository;

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = self::getContainer()->get('cyfrowe_nps.repository.survey');
        assert($repository instanceof SurveyRepositoryInterface);

        $this->repository = $repository;
        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testFindsASurveyByItsToken(): void
    {
        $survey = $this->factory->createSurvey();
        $survey->setToken('a-known-token');
        $this->entityManager->flush();

        self::assertSame($survey->getId(), $this->repository->findOneByToken('a-known-token')?->getId());
    }

    public function testReturnsNothingForAnUnknownToken(): void
    {
        self::assertNull($this->repository->findOneByToken('no-such-token'));
    }

    public function testFindsASurveyByItsOrder(): void
    {
        $order = $this->factory->createOrder();
        $survey = $this->factory->createSurvey($order);
        $this->entityManager->flush();

        self::assertSame($survey->getId(), $this->repository->findOneByOrder($order)?->getId());
    }

    public function testReturnsNothingForAnOrderWithoutASurvey(): void
    {
        $order = $this->factory->createOrder();
        $this->entityManager->flush();

        self::assertNull($this->repository->findOneByOrder($order));
    }

    public function testReturnsOnlyPendingSurveysThatHaveMatured(): void
    {
        $mature = $this->factory->createSurvey(channelCode: 'DUE');
        $mature->setQualifiedAt(new \DateTimeImmutable('2026-09-01 10:00:00'));

        $tooYoung = $this->factory->createSurvey(channelCode: 'DUE');
        $tooYoung->setQualifiedAt(new \DateTimeImmutable('2026-09-20 10:00:00'));

        $alreadySent = $this->factory->createSurvey(status: SurveyStatus::Sent, channelCode: 'DUE');
        $alreadySent->setQualifiedAt(new \DateTimeImmutable('2026-09-01 10:00:00'));

        $otherChannel = $this->factory->createSurvey(channelCode: 'OTHER');
        $otherChannel->setQualifiedAt(new \DateTimeImmutable('2026-09-01 10:00:00'));

        $this->entityManager->flush();

        $due = $this->repository->findDueForSending('DUE', new \DateTimeImmutable('2026-09-10 10:00:00'), 100);

        self::assertCount(1, $due);
        self::assertSame($mature->getId(), $due[0]->getId());
    }

    public function testHonoursTheSendingLimit(): void
    {
        foreach (range(1, 3) as $ignored) {
            $survey = $this->factory->createSurvey(channelCode: 'LIMITED');
            $survey->setQualifiedAt(new \DateTimeImmutable('2026-09-01 10:00:00'));
        }
        $this->entityManager->flush();

        self::assertCount(
            2,
            $this->repository->findDueForSending('LIMITED', new \DateTimeImmutable('2026-09-10 10:00:00'), 2),
        );
    }

    public function testSeesASurveySentToTheCustomerWithinTheCooldown(): void
    {
        $customer = $this->factory->createCustomer();
        $survey = $this->factory->createSurvey(customer: $customer);
        $survey->setSentAt(new \DateTimeImmutable('2026-09-10 10:00:00'));
        $this->entityManager->flush();

        self::assertTrue(
            $this->repository->hasBeenSentToCustomerSince($customer, new \DateTimeImmutable('2026-09-01 00:00:00')),
        );
    }

    public function testIgnoresASurveySentBeforeTheCooldownWindow(): void
    {
        $customer = $this->factory->createCustomer();
        $survey = $this->factory->createSurvey(customer: $customer);
        $survey->setSentAt(new \DateTimeImmutable('2026-06-01 10:00:00'));
        $this->entityManager->flush();

        self::assertFalse(
            $this->repository->hasBeenSentToCustomerSince($customer, new \DateTimeImmutable('2026-09-01 00:00:00')),
        );
    }

    public function testCollectsCriticalScoresWhoseGraceWindowHasPassed(): void
    {
        $waited = $this->factory->createSurvey(channelCode: 'ALERT');
        $waited->setScore(2);
        $waited->setScoreSetAt(new \DateTimeImmutable('2026-09-10 10:00:00'));

        $stillInWindow = $this->factory->createSurvey(channelCode: 'ALERT');
        $stillInWindow->setScore(1);
        $stillInWindow->setScoreSetAt(new \DateTimeImmutable('2026-09-10 11:59:00'));

        $this->entityManager->flush();

        $awaiting = $this->repository->findAwaitingCriticalNotification(
            'ALERT',
            6,
            new \DateTimeImmutable('2026-09-10 11:30:00'),
            3,
            100,
        );

        self::assertCount(1, $awaiting);
        self::assertSame($waited->getId(), $awaiting[0]->getId());
    }

    public function testCollectsACriticalScoreAsSoonAsTheFormComesIn(): void
    {
        $completed = $this->factory->createSurvey(channelCode: 'ALERT_DONE');
        $completed->setScore(3);
        $completed->setScoreSetAt(new \DateTimeImmutable('2026-09-10 11:59:00'));
        $completed->setCompletedAt(new \DateTimeImmutable('2026-09-10 11:59:30'));
        $this->entityManager->flush();

        $awaiting = $this->repository->findAwaitingCriticalNotification(
            'ALERT_DONE',
            6,
            new \DateTimeImmutable('2026-09-10 11:30:00'),
            3,
            100,
        );

        self::assertCount(1, $awaiting);
    }

    public function testSkipsScoresAboveTheThresholdAndThoseAlreadyNotified(): void
    {
        $promoter = $this->factory->createSurvey(channelCode: 'ALERT_SKIP');
        $promoter->setScore(9);
        $promoter->setScoreSetAt(new \DateTimeImmutable('2026-09-10 10:00:00'));

        $alreadyNotified = $this->factory->createSurvey(channelCode: 'ALERT_SKIP');
        $alreadyNotified->setScore(2);
        $alreadyNotified->setScoreSetAt(new \DateTimeImmutable('2026-09-10 10:00:00'));
        $alreadyNotified->setCriticalNotifiedAt(new \DateTimeImmutable('2026-09-10 10:31:00'));

        $withoutScore = $this->factory->createSurvey(channelCode: 'ALERT_SKIP');

        $this->entityManager->flush();

        self::assertSame([], $this->repository->findAwaitingCriticalNotification(
            'ALERT_SKIP',
            6,
            new \DateTimeImmutable('2026-09-10 11:30:00'),
            3,
            100,
        ));
    }

    public function testTheAdminListFetchesTheCustomerAlongWithTheSurvey(): void
    {
        $this->factory->createSurvey();
        $this->entityManager->flush();
        $this->entityManager->clear();

        $surveys = $this->repository->createAdminListQueryBuilder()->getQuery()->getResult();

        self::assertNotSame([], $surveys);

        foreach ($surveys as $survey) {
            assert($survey instanceof SurveyInterface);
            $customer = $survey->getCustomer();

            if (null === $customer) {
                continue;
            }

            self::assertFalse(
                $customer instanceof Proxy && !$customer->__isInitialized(),
                'The plugin keeps no address of its own, so an uninitialised customer means a query per row.',
            );
        }
    }

    public function testTheAdminListAcceptsAFilterReachingThroughToTheCustomer(): void
    {
        $customer = $this->factory->createCustomer();
        $this->factory->createSurvey(customer: $customer);
        $this->entityManager->flush();

        // The grid filters reach through relations by adding their own joins. This proves the
        // pre-joined one does not collide with them.
        $found = $this->repository->createAdminListQueryBuilder()
            ->andWhere('customer.email = :email')
            ->setParameter('email', $customer->getEmail())
            ->getQuery()
            ->getResult()
        ;

        self::assertCount(1, $found);
    }







    public function testARecordWhoseAlertKeepsFailingDropsOutAfterTheLastAttempt(): void
    {
        $survey = $this->critical('ALERT', 2, '2026-09-21 09:00:00');
        $survey->setCriticalAttempts(2);
        $this->entityManager->flush();

        self::assertCount(1, $this->awaiting(), 'Two failures out of three still leave one to go.');

        $survey->setCriticalAttempts(3);
        $this->entityManager->flush();

        self::assertCount(0, $this->awaiting(), 'One undeliverable address must not fill the log on every run.');
    }

    public function testARecordFlaggedAsAutomatedIsPickedUpLikeAnyOther(): void
    {
        $survey = $this->critical('ALERT', 1, '2026-09-21 09:00:00');
        $survey->setBotSuspected(true);
        $this->entityManager->flush();

        // A mail scanner flagging the record must not silence a one the customer really gave.
        self::assertCount(1, $this->awaiting());
    }

    public function testOnlyTheChannelAskedAboutIsConsidered(): void
    {
        $this->critical('ALERT', 2, '2026-09-21 09:00:00');
        $this->critical('SOMEWHERE_ELSE', 2, '2026-09-21 09:00:00');
        $this->entityManager->flush();

        self::assertCount(1, $this->awaiting());
    }


    public function testACorrectionIsDueOnceTheScoreClimbsAboveTheThreshold(): void
    {
        $survey = $this->alerted(8);
        $this->entityManager->flush();

        $due = $this->repository->findAwaitingCorrectionNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50);

        self::assertCount(1, $due);
        self::assertSame($survey->getId(), $due[0]->getId());
    }

    public function testNoCorrectionIsDueWhileTheScoreStaysCritical(): void
    {
        $this->alerted(2);
        $this->entityManager->flush();

        self::assertCount(0, $this->repository->findAwaitingCorrectionNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
    }

    public function testNoCorrectionIsDueForARecordNobodyWasAlertedAbout(): void
    {
        $survey = $this->critical('ALERT', 8, '2026-09-21 09:00:00');
        $survey->setCriticalNotifiedAt(null);
        $this->entityManager->flush();

        self::assertCount(0, $this->repository->findAwaitingCorrectionNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50), 'There is nothing to correct.');
    }

    public function testACorrectionIsOnlyEverDueOnce(): void
    {
        $survey = $this->alerted(8);
        $survey->setCriticalCorrectionNotifiedAt(new \DateTimeImmutable('2026-09-21 11:00:00'));
        $this->entityManager->flush();

        self::assertCount(0, $this->repository->findAwaitingCorrectionNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
    }

    public function testACommentNoticeIsDueWhenTheFormArrivesAfterTheAlert(): void
    {
        $survey = $this->alerted(2);
        $survey->setComment('Adding to my earlier score.');
        $survey->setCompletedAt(new \DateTimeImmutable('2026-09-21 10:30:00'));
        $this->entityManager->flush();

        self::assertCount(1, $this->repository->findAwaitingCommentNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
    }

    public function testNoCommentNoticeIsDueWhenTheAlertAlreadyCarriedIt(): void
    {
        $survey = $this->alerted(2);
        $survey->setComment('Said it all the first time.');
        // Submitted before the alert went out, so the alert already quoted it.
        $survey->setCompletedAt(new \DateTimeImmutable('2026-09-21 09:30:00'));
        $this->entityManager->flush();

        self::assertCount(0, $this->repository->findAwaitingCommentNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
    }

    public function testACommentThatArrivesWithABetterScoreIsLeftToTheCorrection(): void
    {
        $survey = $this->alerted(8);
        $survey->setComment('Sorted after a phone call, changing my score.');
        $survey->setCompletedAt(new \DateTimeImmutable('2026-09-21 10:30:00'));
        $this->entityManager->flush();

        self::assertCount(0, $this->repository->findAwaitingCommentNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50), 'One event, one message.');
        self::assertCount(1, $this->repository->findAwaitingCorrectionNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
    }

    public function testACommentNoticeIsOnlyEverDueOnce(): void
    {
        $survey = $this->alerted(2);
        $survey->setComment('Adding to my earlier score.');
        $survey->setCompletedAt(new \DateTimeImmutable('2026-09-21 10:30:00'));
        $survey->setCriticalCommentNotifiedAt(new \DateTimeImmutable('2026-09-21 10:35:00'));
        $this->entityManager->flush();

        self::assertCount(0, $this->repository->findAwaitingCommentNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
    }

    public function testNoFollowUpIsDueOnceTheLinkHasExpired(): void
    {
        $correction = $this->alerted(8);
        $correction->setExpiresAt(new \DateTimeImmutable('2026-09-20 10:00:00'));

        $comment = $this->alerted(2);
        $comment->setComment('Adding to my earlier score.');
        $comment->setCompletedAt(new \DateTimeImmutable('2026-09-21 10:30:00'));
        $comment->setExpiresAt(new \DateTimeImmutable('2026-09-20 10:00:00'));

        $this->entityManager->flush();

        // Past that date the shop refuses both a new score and the form, so neither follow-up can
        // arise - and the query stops reading records that can never qualify again.
        self::assertCount(0, $this->repository->findAwaitingCorrectionNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
        self::assertCount(0, $this->repository->findAwaitingCommentNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
    }

    public function testAFollowUpIsStillDueForARecordWithNoExpiryAtAll(): void
    {
        $survey = $this->alerted(8);
        self::assertNull($survey->getExpiresAt());
        $this->entityManager->flush();

        // Survey::isExpired() calls a record without a date open, and the query has to agree.
        self::assertCount(1, $this->repository->findAwaitingCorrectionNotice('ALERT', 6, $this->whileTheLinkLives(), 3, 50));
    }

    private function whileTheLinkLives(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-21 11:00:00');
    }

    private function alerted(int $scoreNow): Survey
    {
        $survey = $this->critical('ALERT', $scoreNow, '2026-09-21 09:00:00');
        $survey->setCriticalNotifiedAt(new \DateTimeImmutable('2026-09-21 10:00:00'));

        return $survey;
    }

    /**
     * @return list<\Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface>
     */
    private function awaiting(int $threshold = 6, int $maxAttempts = 3): array
    {
        return $this->repository->findAwaitingCriticalNotification(
            'ALERT',
            $threshold,
            // Thirty minutes before the moment the run pretends to happen at.
            new \DateTimeImmutable('2026-09-21 09:30:00'),
            $maxAttempts,
            50,
        );
    }

    private function critical(string $channelCode, int $score, string $scoredAt): Survey
    {
        $survey = $this->factory->createSurvey(channelCode: $channelCode);
        $survey->setScore($score);
        $survey->setScoreSetAt(new \DateTimeImmutable($scoredAt));

        return $survey;
    }

    public function testListsTheDistinctValuesOfAColumnInAlphabeticalOrder(): void
    {
        $this->factory->createSurvey();

        foreach (['Pickup point', 'Express courier', 'Pickup point'] as $name) {
            $this->factory->createSurvey()->setShippingMethodName($name);
        }

        $this->entityManager->flush();

        self::assertSame(
            ['Express courier' => 'Express courier', 'Pickup point' => 'Pickup point'],
            $this->repository->findDistinctValues('shippingMethodName'),
        );
    }

    public function testRefusesToReadTheDistinctValuesOfAFieldThatIsNotFilterable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->findDistinctValues('token');
    }
}
