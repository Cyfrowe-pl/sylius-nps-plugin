<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Command;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class NotifyCriticalCommandTest extends IntegrationTestCase
{
    private const CHANNEL = 'NOTIFY_TEST';

    private CommandTester $command;

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $this->command = new CommandTester($application->find('nps:notify:critical'));

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        (new ShopFixture($this->entityManager, $cache))->createChannel(self::CHANNEL, 'Notify test', 'notify.test');
        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testAScoreThatWaitedOutTheWindowIsAlertedAbout(): void
    {
        $this->settings();
        $survey = $this->scored(2, '-40 minutes');

        $this->command->execute([]);

        self::assertSame(1, $this->alertsSent());
        self::assertNotNull($survey->getCriticalNotifiedAt());
        self::assertStringContainsString('Klient nie zostawi', $this->lastBody(), 'The alert has to say the form never came in.');
    }

    public function testAScoreTheBotGuardIsSuspiciousOfIsStillAlertedAbout(): void
    {
        $this->settings();
        $survey = $this->scored(2, '-40 minutes');
        $survey->setBotSuspected(true);
        $this->entityManager->flush();

        $this->command->execute([]);

        self::assertSame(
            1,
            $this->alertsSent(),
            'A mail scanner flagging the record must not silence a score the customer really gave.',
        );
        self::assertNotNull($survey->getCriticalNotifiedAt());
    }

    public function testAScoreStillInsideTheWindowWaits(): void
    {
        $this->settings();
        $survey = $this->scored(2, '-5 minutes');

        $this->command->execute([]);

        self::assertSame([], self::getMailerEvents());
        self::assertNull($survey->getCriticalNotifiedAt());
    }

    public function testAScoreCorrectedInsideTheWindowIsNeverAlertedAbout(): void
    {
        $this->settings();

        // Clicked 2 by mistake, put 8 in the form, all inside the grace window.
        $survey = $this->scored(8, '-5 minutes');
        $survey->setCompletedAt(new \DateTimeImmutable('-4 minutes'));
        $this->entityManager->flush();

        $this->command->execute([]);

        self::assertSame([], self::getMailerEvents(), 'The whole point of the window is to swallow this one.');
        self::assertNull($survey->getCriticalNotifiedAt());
    }

    public function testACriticalScoreWithTheFormBehindItGoesOutAtOnce(): void
    {
        $this->settings();

        $survey = $this->scored(2, '-1 minute');
        $survey->setComment('The parcel arrived open.');
        $survey->setCompletedAt(new \DateTimeImmutable('-30 seconds'));
        $this->entityManager->flush();

        $this->command->execute([]);

        self::assertSame(1, $this->alertsSent(), 'There is nothing left to wait for once the form is in.');
        self::assertStringContainsString('The parcel arrived open.', $this->lastBody());
    }

    public function testTheSameRecordIsNeverAlertedAboutTwice(): void
    {
        $this->settings();
        $this->scored(2, '-40 minutes');

        $this->command->execute([]);
        $stampedAt = $this->onlySurvey()->getCriticalNotifiedAt();

        $this->command->execute([]);

        self::assertStringContainsString('0 alerts sent', $this->command->getDisplay());
        self::assertSame($stampedAt, $this->onlySurvey()->getCriticalNotifiedAt(), 'The mark may not move either.');
    }

    public function testNotificationsTurnedOffStopTheAlerts(): void
    {
        $configuration = $this->settings();
        $configuration->setNotifyEnabled(false);
        $this->entityManager->flush();

        $survey = $this->scored(2, '-40 minutes');

        $this->command->execute([]);

        self::assertSame([], self::getMailerEvents());
        self::assertNull($survey->getCriticalNotifiedAt());
    }

    public function testADryRunSendsNothingAndMarksNothing(): void
    {
        $this->settings();
        $survey = $this->scored(2, '-40 minutes');

        $this->command->execute(['--dry-run' => true]);

        self::assertSame([], self::getMailerEvents());
        self::assertNull($survey->getCriticalNotifiedAt());
        self::assertStringContainsString('Would send', $this->command->getDisplay());
    }

    public function testARunThatCannotTakeTheLockSendsNothing(): void
    {
        $this->settings();
        $this->scored(2, '-40 minutes');

        $lockFactory = self::getContainer()->get('cyfrowe_nps.lock.factory');
        assert($lockFactory instanceof LockFactory);
        $held = $lockFactory->createLock('cyfrowe_nps_notify_critical');
        self::assertTrue($held->acquire());

        $this->command->execute([]);

        self::assertSame([], self::getMailerEvents(), 'Two runs overlapping would mean two emails about one score.');
        self::assertStringContainsString('Another run is still going', $this->command->getDisplay());

        $held->release();
    }

    public function testACorrectionFollowsTheAlertItCorrects(): void
    {
        $this->settings();
        $survey = $this->scored(2, '-40 minutes');

        $this->command->execute([]);
        self::assertSame(1, $this->alertsSent());

        $survey->setScore(8);
        $survey->setCompletedAt(new \DateTimeImmutable('now'));
        $this->entityManager->flush();

        $this->command->execute([]);

        self::assertSame(2, $this->alertsSent());
        self::assertStringContainsString('skorygowana na 8', $this->lastSubject());
        self::assertNotNull($survey->getCriticalCorrectionNotifiedAt());
    }

    public function testALateCommentFollowsTheAlertItBelongsTo(): void
    {
        $this->settings();
        $survey = $this->scored(2, '-40 minutes');

        $this->command->execute([]);

        $survey->setComment('Adding what I forgot to say.');
        // Explicitly after the mark rather than "now": the two would otherwise land in the same
        // second, and the follow-up is due only for a form that arrived later than the alert.
        $alertedAt = $survey->getCriticalNotifiedAt();
        assert($alertedAt instanceof \DateTimeInterface);
        $survey->setCompletedAt(\DateTimeImmutable::createFromInterface($alertedAt)->modify('+1 minute'));
        $this->entityManager->flush();

        $this->command->execute([]);

        self::assertSame(2, $this->alertsSent());
        self::assertStringContainsString('Komentarz do oceny', $this->lastSubject());
        self::assertStringContainsString('Adding what I forgot to say.', $this->lastBody());
        self::assertNotNull($survey->getCriticalCommentNotifiedAt());
    }

    public function testTheLimitCapsTheWholeRunAndNotEachKindOfAlert(): void
    {
        $this->settings();

        // One record due a primary alert and one due a correction, so the run has two kinds to
        // work through and a budget for one.
        $this->scored(2, '-40 minutes');

        $corrected = $this->scored(8, '-40 minutes');
        $corrected->setCriticalNotifiedAt(new \DateTimeImmutable('-20 minutes'));
        $this->entityManager->flush();

        $this->command->execute(['--limit' => 1]);

        self::assertSame(1, $this->alertsSent(), 'A per-query budget would let one channel send as many alerts as there are kinds.');
    }

    public function testTheSummaryCountsWhatWentAndWhatDidNot(): void
    {
        $configuration = $this->settings();
        $configuration->setNotifyEmail('this is not an address');
        $this->entityManager->flush();

        $survey = $this->scored(2, '-40 minutes');

        $this->command->execute([]);

        self::assertSame(0, $this->alertsSent());
        self::assertStringContainsString('0 alerts sent', $this->command->getDisplay());
        self::assertStringContainsString('1 could not be sent', $this->command->getDisplay());
        self::assertNull($survey->getCriticalNotifiedAt());
        self::assertSame(1, $survey->getCriticalAttempts());
    }

    /**
     * One email shows up as two events here: the test application hands mail to Messenger and a
     * synchronous transport delivers it straight away, so every message is recorded once on its
     * way in and once on its way out. Only the deliveries are counted.
     */
    private function alertsSent(): int
    {
        return count(array_filter(
            self::getMailerEvents(),
            static fn (MessageEvent $event): bool => !$event->isQueued(),
        ));
    }

    private function lastEmail(): ?Email
    {
        $messages = self::getMailerMessages();
        $last = [] === $messages ? null : end($messages);

        return $last instanceof Email ? $last : null;
    }

    private function lastBody(): string
    {
        return (string) $this->lastEmail()?->getHtmlBody();
    }

    private function lastSubject(): string
    {
        return (string) $this->lastEmail()?->getSubject();
    }

    private function onlySurvey(): Survey
    {
        $survey = $this->entityManager->getRepository(Survey::class)->findOneBy(['channelCode' => self::CHANNEL]);
        assert($survey instanceof Survey);

        return $survey;
    }

    private function settings(): Configuration
    {
        $configuration = new Configuration();
        $configuration->setChannelCode(self::CHANNEL);
        $configuration->setNotifyEmail('complaints@example.com');

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }

    private function scored(int $score, string $scoredAt): Survey
    {
        $survey = $this->factory->createSurvey(channelCode: self::CHANNEL);
        $survey->setScore($score);
        $survey->setScoreSetAt(new \DateTimeImmutable($scoredAt));
        $survey->setOrderNumber('ORD-48198');
        $this->entityManager->flush();

        return $survey;
    }
}
