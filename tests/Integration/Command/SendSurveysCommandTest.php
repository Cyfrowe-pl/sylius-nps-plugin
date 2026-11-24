<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Command;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class SendSurveysCommandTest extends IntegrationTestCase
{
    use ClockSensitiveTrait;

    private const CHANNEL = 'COMMAND_TEST';

    private CommandTester $command;

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $this->command = new CommandTester($application->find('nps:survey:send'));

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        $shop = new ShopFixture($this->entityManager, $cache);
        $shop->createChannel(self::CHANNEL, 'Command test', 'command.test');

        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testADryRunSendsNothingAndLeavesTheStatusAlone(): void
    {
        $this->configureChannel();
        $survey = $this->dueSurvey();

        $this->command->execute(['--dry-run' => true]);

        self::assertSame(SurveyStatus::Pending, $survey->getStatus(), 'A dry run must not move the record on.');
        self::assertSame([], self::getMailerEvents());
        self::assertStringContainsString('Would dispatch', $this->command->getDisplay());
    }

    public function testItDispatchesASurveyWhoseWaitIsOver(): void
    {
        $this->configureChannel();
        $survey = $this->dueSurvey();

        $this->command->execute([]);

        self::assertNotSame(SurveyStatus::Pending, $survey->getStatus());
        self::assertStringContainsString('Dispatching', $this->command->getDisplay());
    }

    public function testASurveyStillWithinItsWaitIsLeftAlone(): void
    {
        $this->configureChannel();
        $survey = $this->dueSurvey();
        $survey->setQualifiedAt(new \DateTimeImmutable('now'));
        $this->entityManager->flush();

        $this->command->execute([]);

        self::assertSame(SurveyStatus::Pending, $survey->getStatus());
    }

    public function testADisabledChannelIsNotEvenLookedAt(): void
    {
        $this->configureChannel(enabled: false);
        $survey = $this->dueSurvey();

        $this->command->execute([]);

        self::assertSame(SurveyStatus::Pending, $survey->getStatus());
    }

    public function testASecondRunDoesNothingWhileTheFirstHoldsTheLock(): void
    {
        $this->configureChannel();
        $survey = $this->dueSurvey();

        $factory = self::getContainer()->get('cyfrowe_nps.lock.factory');
        assert($factory instanceof LockFactory);

        $held = $factory->createLock('cyfrowe_nps_survey_send');
        self::assertTrue($held->acquire(), 'The test needs the lock to set the scene.');

        try {
            $this->command->execute([]);
        } finally {
            $held->release();
        }

        self::assertSame(SurveyStatus::Pending, $survey->getStatus(), 'Two runs overlapping would mean two emails.');
        self::assertStringContainsString('Another run', $this->command->getDisplay());
    }

    public function testTheWindowIsCaughtUpWithRatherThanMatchedExactly(): void
    {
        self::mockTime('today 11:30');
        $this->configureChannel(sendHourFrom: 10, sendHourTo: 20);
        $survey = $this->dueSurvey();

        $this->command->execute([]);

        self::assertNotSame(SurveyStatus::Pending, $survey->getStatus(), 'A run at eleven picks up what a failed run at ten left behind.');
    }

    public function testNothingGoesOutBeforeTheWindowOpens(): void
    {
        self::mockTime('today 09:59');
        $this->configureChannel(sendHourFrom: 10, sendHourTo: 20);
        $survey = $this->dueSurvey();

        $this->command->execute([]);

        self::assertSame(SurveyStatus::Pending, $survey->getStatus());
    }

    public function testNothingGoesOutOnceTheWindowHasClosed(): void
    {
        self::mockTime('today 20:00');
        $this->configureChannel(sendHourFrom: 10, sendHourTo: 20);
        $survey = $this->dueSurvey();

        $this->command->execute([]);

        self::assertSame(SurveyStatus::Pending, $survey->getStatus(), 'The last email goes out before the closing hour.');
    }

    public function testTheLastHourOfTheWindowStillSends(): void
    {
        self::mockTime('today 19:59');
        $this->configureChannel(sendHourFrom: 10, sendHourTo: 20);
        $survey = $this->dueSurvey();

        $this->command->execute([]);

        self::assertNotSame(SurveyStatus::Pending, $survey->getStatus());
    }

    public function testARoundTheClockWindowSendsInTheLastHourOfTheDay(): void
    {
        self::mockTime('today 23:30');
        $this->configureChannel(sendHourFrom: 0, sendHourTo: 24);
        $survey = $this->dueSurvey();

        $this->command->execute([]);

        self::assertNotSame(SurveyStatus::Pending, $survey->getStatus());
    }

    private function configureChannel(bool $enabled = true, int $sendHourFrom = 0, int $sendHourTo = 24): Configuration
    {
        $configuration = new Configuration();
        $configuration->setChannelCode(self::CHANNEL);
        $configuration->setEnabled($enabled);
        $configuration->setSendHourFrom($sendHourFrom);
        $configuration->setSendHourTo($sendHourTo);
        $configuration->setDelayDays(5);

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }

    private function dueSurvey(): Survey
    {
        $survey = $this->factory->createSurvey(channelCode: self::CHANNEL);
        $survey->setToken(bin2hex(random_bytes(8)));
        $survey->setQualifiedAt(new \DateTimeImmutable('-10 days'));
        $this->entityManager->flush();

        return $survey;
    }
}
