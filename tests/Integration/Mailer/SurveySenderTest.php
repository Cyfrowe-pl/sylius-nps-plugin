<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Mailer;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\SkipReason;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Mailer\SurveyEmailData;
use Cyfrowe\SyliusNpsPlugin\Mailer\SurveySender;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class SurveySenderTest extends IntegrationTestCase
{
    private const CHANNEL = 'SENDING_TEST';

    private SurveySender $sender;

    private SurveyFactory $factory;

    private ChannelInterface $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $sender = self::getContainer()->get('cyfrowe_nps.mailer.survey_sender');
        assert($sender instanceof SurveySender);
        $this->sender = $sender;

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        $this->channel = (new ShopFixture($this->entityManager, $cache))->createChannel(self::CHANNEL, 'Sending test');
        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testItSendsTheSurveyAndStampsTheRecord(): void
    {
        $this->configureChannel();
        $survey = $this->survey();

        $this->sender->send($survey);

        self::assertSame(SurveyStatus::Sent, $survey->getStatus());
        self::assertNotNull($survey->getSentAt());
        self::assertNotNull($survey->getExpiresAt());
        self::assertGreaterThan($survey->getSentAt(), $survey->getExpiresAt());
    }

    public function testTheSubjectComesFromTheChannelSettings(): void
    {
        $configuration = $this->configureChannel();
        $configuration->getTranslation('pl_PL')->setEmailSubject('Jak nam wyszło z zamówieniem?');
        $this->entityManager->flush();

        $this->sender->send($this->survey());

        $email = self::getMailerMessage();
        assert($email instanceof Email);
        self::assertSame('Jak nam wyszło z zamówieniem?', $email->getSubject());
    }

    public function testTheEmailCarriesAllElevenScoreLinks(): void
    {
        $this->configureChannel();
        $survey = $this->survey();

        $this->sender->send($survey);

        $email = self::getMailerMessage();
        assert($email instanceof Email);

        $body = (string) $email->getHtmlBody();
        $token = (string) $survey->getToken();

        foreach (range(0, 10) as $score) {
            self::assertStringContainsString(
                sprintf('/pl_PL/nps/%s/%d', $token, $score),
                $body,
                sprintf('The link for score %d is missing.', $score),
            );
        }

        self::assertStringContainsString('http://localhost/', $body, 'A link in an email has to be absolute.');
    }

    public function testASendThatFailsIsLeftToMessengerToRetry(): void
    {
        $this->configureChannel();
        $survey = $this->survey();
        $survey->setStatus(SurveyStatus::Queued);

        $customer = $survey->getOrder()?->getCustomer();
        assert($customer instanceof CustomerInterface);
        $customer->setEmail('this is not an address');
        $this->entityManager->flush();

        $thrown = null;

        try {
            $this->sender->send($survey);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown, 'A failed send has to reach Messenger, which is the one that retries it.');
        self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, $thrown, 'Nothing went out, so the message has to stay retryable.');
        self::assertSame(SurveyStatus::Queued, $survey->getStatus(), 'The retried message has to find the survey still due.');
        self::assertNull($survey->getSentAt());
    }

    public function testASurveySentButNotSavedIsNotRetried(): void
    {
        $this->configureChannel();
        $survey = $this->survey();
        $survey->setStatus(SurveyStatus::Queued);
        $this->entityManager->flush();

        $sender = $this->senderWhoseFlushFails();

        $thrown = null;

        try {
            $sender->send($survey);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(UnrecoverableExceptionInterface::class, $thrown, 'Retrying a survey that went out would send it again.');
        self::assertCount(1, self::getMailerMessages(), 'The email went out before the state was saved.');
    }

    public function testASurveyWhoseChannelHasBeenRemovedIsSkippedRatherThanCrashing(): void
    {
        $orphaned = new Configuration();
        $orphaned->setChannelCode('CHANNEL_SINCE_REMOVED');
        $this->entityManager->persist($orphaned);

        $order = $this->factory->createOrder(null, $this->channel);
        $survey = $this->factory->createSurvey($order, channelCode: 'CHANNEL_SINCE_REMOVED');
        $survey->setToken(bin2hex(random_bytes(8)));
        $this->entityManager->flush();

        $this->sender->send($survey);

        self::assertSame(SurveyStatus::Skipped, $survey->getStatus());
        self::assertSame(SkipReason::ChannelMissing, $survey->getSkipReason());
        self::assertSame([], self::getMailerEvents(), 'The email cannot be built without a channel, so none is attempted.');
    }

    public function testAnOrderWithoutARecipientIsSkipped(): void
    {
        $this->configureChannel();
        $survey = $this->survey();
        $order = $survey->getOrder();
        assert($order instanceof OrderInterface);
        $order->setCustomer(null);
        $this->entityManager->flush();

        $this->sender->send($survey);

        self::assertSame(SurveyStatus::Skipped, $survey->getStatus());
        self::assertSame(SkipReason::NoRecipient, $survey->getSkipReason());
        self::assertSame([], self::getMailerEvents(), 'Nothing should have been sent.');
    }

    public function testADisabledChannelIsSkipped(): void
    {
        $this->configureChannel(enabled: false);

        $survey = $this->survey();
        $this->sender->send($survey);

        self::assertSame(SurveyStatus::Skipped, $survey->getStatus());
        self::assertSame(SkipReason::ChannelDisabled, $survey->getSkipReason());
    }

    public function testACancelledOrderIsSkipped(): void
    {
        $this->configureChannel();
        $survey = $this->survey();
        $order = $survey->getOrder();
        assert($order instanceof OrderInterface);
        $order->setState(OrderInterface::STATE_CANCELLED);
        $this->entityManager->flush();

        $this->sender->send($survey);

        self::assertSame(SurveyStatus::Skipped, $survey->getStatus());
        self::assertSame(SkipReason::OrderCancelled, $survey->getSkipReason());
    }

    public function testASurveyAlreadySentIsNotSentAgain(): void
    {
        $this->configureChannel();
        $survey = $this->survey();
        $survey->setStatus(SurveyStatus::Sent);
        $this->entityManager->flush();

        $this->sender->send($survey);

        self::assertSame([], self::getMailerEvents(), 'A repeated message must not put a second email out.');
    }

    public function testACustomerInsideTheirCooldownIsSkipped(): void
    {
        $configuration = $this->configureChannel();
        $configuration->setCooldownDays(90);
        $this->entityManager->flush();

        $survey = $this->survey();
        $customer = $survey->getCustomer();
        self::assertNotNull($customer);

        // An earlier survey went to the same person a fortnight ago.
        $earlier = $this->factory->createSurvey(customer: $customer, channelCode: self::CHANNEL);
        $earlier->setStatus(SurveyStatus::Sent);
        $earlier->setSentAt(new \DateTimeImmutable('-14 days'));
        $this->entityManager->flush();

        $this->sender->send($survey);

        self::assertSame(SurveyStatus::Skipped, $survey->getStatus());
        self::assertSame(SkipReason::Cooldown, $survey->getSkipReason());
        self::assertSame([], self::getMailerEvents());
    }

    public function testACustomerPastTheirCooldownIsSent(): void
    {
        $configuration = $this->configureChannel();
        $configuration->setCooldownDays(7);
        $this->entityManager->flush();

        $survey = $this->survey();
        $customer = $survey->getCustomer();
        self::assertNotNull($customer);

        $earlier = $this->factory->createSurvey(customer: $customer, channelCode: self::CHANNEL);
        $earlier->setStatus(SurveyStatus::Sent);
        $earlier->setSentAt(new \DateTimeImmutable('-30 days'));
        $this->entityManager->flush();

        $this->sender->send($survey);

        self::assertSame(SurveyStatus::Sent, $survey->getStatus());
    }

    public function testASurveyWhoseOrderIsGoneIsSkipped(): void
    {
        $this->configureChannel();
        $survey = $this->survey();
        $survey->setOrder(null);
        $this->entityManager->flush();

        $this->sender->send($survey);

        self::assertSame(SurveyStatus::Skipped, $survey->getStatus());
        self::assertSame(SkipReason::OrderMissing, $survey->getSkipReason());
    }

    private function senderWhoseFlushFails(): SurveySender
    {
        $container = self::getContainer();

        $surveyRepository = $container->get('cyfrowe_nps.repository.survey');
        $configurationRepository = $container->get('cyfrowe_nps.repository.channel_configuration');
        $channelRepository = $container->get('sylius.repository.channel');
        $emailSender = $container->get('sylius.email_sender');
        $clock = $container->get('clock');
        $data = $container->get('cyfrowe_nps.mailer.email_data');
        $logger = $container->get('logger');
        assert($surveyRepository instanceof SurveyRepositoryInterface);
        assert($configurationRepository instanceof RepositoryInterface);
        assert($channelRepository instanceof RepositoryInterface);
        assert($emailSender instanceof SenderInterface);
        assert($clock instanceof ClockInterface);
        assert($data instanceof SurveyEmailData);
        assert($logger instanceof LoggerInterface);

        $entityManager = new class($this->entityManager) extends EntityManagerDecorator {
            /** @param mixed $entity */
            public function flush($entity = null): void
            {
                throw new \RuntimeException('The database went away.');
            }
        };

        return new SurveySender(
            $surveyRepository,
            $configurationRepository,
            $channelRepository,
            $emailSender,
            $entityManager,
            $clock,
            $data,
            $logger,
        );
    }

    private function configureChannel(bool $enabled = true): Configuration
    {
        $configuration = new Configuration();
        $configuration->setChannelCode(self::CHANNEL);
        $configuration->setEnabled($enabled);

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }

    private function survey(): Survey
    {
        $order = $this->factory->createOrder(null, $this->channel);
        $survey = $this->factory->createSurvey($order, channelCode: self::CHANNEL);
        $survey->setToken(bin2hex(random_bytes(8)));
        $this->entityManager->flush();

        return $survey;
    }
}
