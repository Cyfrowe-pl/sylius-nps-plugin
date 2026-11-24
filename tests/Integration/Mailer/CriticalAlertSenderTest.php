<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Mailer;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertKind;
use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertSender;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class CriticalAlertSenderTest extends IntegrationTestCase
{
    private const CHANNEL = 'ALERT_SENDER';

    private CriticalAlertSender $alertSender;

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $alertSender = self::getContainer()->get('cyfrowe_nps.mailer.critical_alert_sender');
        assert($alertSender instanceof CriticalAlertSender);
        $this->alertSender = $alertSender;

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        (new ShopFixture($this->entityManager, $cache))->createChannel(self::CHANNEL, 'Alert sender test');
        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testItSendsTheAlertAndMarksTheRecord(): void
    {
        $survey = $this->criticalAnswer();
        $configuration = $this->settings('complaints@example.com');

        self::assertTrue($this->alertSender->send($survey, $configuration, CriticalAlertKind::Primary));

        $email = self::getMailerMessage();
        assert($email instanceof Email);
        self::assertSame('complaints@example.com', $email->getTo()[0]->getAddress());
        self::assertNotNull($survey->getCriticalNotifiedAt());
    }

    public function testAChannelWithNoAddressSendsNothingAndLeavesTheRecordAlone(): void
    {
        $survey = $this->criticalAnswer();
        $configuration = $this->settings(null);

        self::assertFalse($this->alertSender->send($survey, $configuration, CriticalAlertKind::Primary));

        self::assertSame([], self::getMailerEvents());
        self::assertNull($survey->getCriticalNotifiedAt());
        self::assertSame(
            0,
            $survey->getCriticalAttempts(),
            'A half-filled settings screen is not a failed attempt: filling the address in later still has to alert.',
        );
    }

    public function testAnAddressTheMailerRefusesCountsAsAFailedAttempt(): void
    {
        $survey = $this->criticalAnswer();
        $configuration = $this->settings('this is not an address');

        self::assertFalse($this->alertSender->send($survey, $configuration, CriticalAlertKind::Primary));

        self::assertNull($survey->getCriticalNotifiedAt(), 'Nothing went out, so nothing may be marked as sent.');
        self::assertSame(1, $survey->getCriticalAttempts());
    }

    public function testTheCountReachesItsLimitAndStopsThere(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setCriticalAttempts(CriticalAlertSender::MAX_ATTEMPTS - 1);
        $configuration = $this->settings('this is not an address');

        $this->alertSender->send($survey, $configuration, CriticalAlertKind::Primary);

        self::assertSame(CriticalAlertSender::MAX_ATTEMPTS, $survey->getCriticalAttempts());
    }

    public function testASendThatGetsThroughClearsTheEarlierFailures(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setCriticalAttempts(2);
        $configuration = $this->settings('complaints@example.com');

        $this->alertSender->send($survey, $configuration, CriticalAlertKind::Primary);

        self::assertSame(
            0,
            $survey->getCriticalAttempts(),
            'Otherwise a record that failed twice and then got through is one failure from being dropped for good.',
        );
    }

    public function testEachKindOfAlertMarksAColumnOfItsOwn(): void
    {
        $configuration = $this->settings('complaints@example.com');

        $correction = $this->criticalAnswer();
        $this->alertSender->send($correction, $configuration, CriticalAlertKind::Correction);

        $comment = $this->criticalAnswer();
        $this->alertSender->send($comment, $configuration, CriticalAlertKind::Comment);

        self::assertNotNull($correction->getCriticalCorrectionNotifiedAt());
        self::assertNull($correction->getCriticalNotifiedAt());
        self::assertNotNull($comment->getCriticalCommentNotifiedAt());
        self::assertNull($comment->getCriticalCorrectionNotifiedAt());
    }

    private function settings(?string $notifyEmail): Configuration
    {
        $configuration = new Configuration();
        $configuration->setChannelCode(self::CHANNEL);
        $configuration->setNotifyEmail($notifyEmail);

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }

    private function criticalAnswer(): Survey
    {
        $survey = $this->factory->createSurvey(channelCode: self::CHANNEL);
        $survey->setScore(2);
        $survey->setScoreSetAt(new \DateTimeImmutable('2026-09-20 10:00:00'));
        $this->entityManager->flush();

        return $survey;
    }
}
