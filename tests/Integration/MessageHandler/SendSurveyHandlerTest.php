<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\MessageHandler;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Message\SendSurvey;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class SendSurveyHandlerTest extends IntegrationTestCase
{
    private const CHANNEL = 'HANDLER_TEST';

    private MessageBusInterface $bus;

    private SurveyFactory $factory;

    private ChannelInterface $channel;

    private Configuration $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $bus = self::getContainer()->get('messenger.default_bus');
        assert($bus instanceof MessageBusInterface);
        $this->bus = $bus;

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        $this->channel = (new ShopFixture($this->entityManager, $cache))->createChannel(self::CHANNEL, 'Handler test');
        $this->factory = new SurveyFactory($this->entityManager);

        $this->configuration = new Configuration();
        $this->configuration->setChannelCode(self::CHANNEL);
        $this->configuration->setEnabled(true);
        $this->entityManager->persist($this->configuration);
        $this->entityManager->flush();
    }

    public function testTheMessageSendsTheSurveyItNames(): void
    {
        $survey = $this->survey();

        $this->bus->dispatch(new SendSurvey((int) $survey->getId()));

        self::assertCount(1, self::getMailerMessages());
        self::assertSame(SurveyStatus::Sent, $survey->getStatus());
    }

    public function testAMessageForARecordThatIsGoneIsNotAComplaint(): void
    {
        $this->bus->dispatch(new SendSurvey(987654));

        self::assertSame(
            [],
            self::getMailerMessages(),
            'A record deleted between dispatch and delivery is nothing to send and nothing to raise.',
        );
    }

    public function testTheSameMessageDeliveredTwiceSendsOneEmail(): void
    {
        $this->configuration->setCooldownDays(0);
        $survey = $this->survey();
        $message = new SendSurvey((int) $survey->getId());

        $this->bus->dispatch($message);
        $this->bus->dispatch($message);

        self::assertCount(
            1,
            self::getMailerMessages(),
            'A transport that redelivers must not put a second survey in front of the customer.',
        );
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
