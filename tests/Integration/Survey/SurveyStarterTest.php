<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Survey;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Survey\SurveyStarterInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class SurveyStarterTest extends IntegrationTestCase
{
    private SurveyStarterInterface $starter;

    private SurveyFactory $orders;

    private ChannelInterface $channel;

    /** @var array<string, ShippingMethodInterface> */
    private array $shippingMethods = [];

    protected function setUp(): void
    {
        parent::setUp();

        $starter = self::getContainer()->get('cyfrowe_nps.survey_starter');
        assert($starter instanceof SurveyStarterInterface);
        $this->starter = $starter;

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        $shop = new ShopFixture($this->entityManager, $cache);
        $this->channel = $shop->createChannel('FASHION_WEB', 'Fashion Web Store');

        foreach ($shop->createShippingMethods(['ups', 'dhl_express']) as $method) {
            $this->shippingMethods[(string) $method->getCode()] = $method;
        }

        $this->orders = new SurveyFactory($this->entityManager);
    }

    public function testCopiesTheOrderDetailsOntoANewPendingSurvey(): void
    {
        $this->configureChannel();
        $order = $this->order('ups');

        $survey = $this->starter->start($order);

        self::assertNotNull($survey);
        self::assertSame(SurveyStatus::Pending, $survey->getStatus());
        self::assertNotNull($survey->getToken());
        self::assertNotNull($survey->getQualifiedAt());
        self::assertSame($order, $survey->getOrder());
        self::assertSame($order->getNumber(), $survey->getOrderNumber());
        self::assertSame($order->getCustomer(), $survey->getCustomer());
        self::assertSame('FASHION_WEB', $survey->getChannelCode());
        self::assertSame('pl_PL', $survey->getLocaleCode());
        self::assertSame('PLN', $survey->getCurrencyCode());
        self::assertSame($order->getTotal(), $survey->getOrderTotal());
        self::assertSame('ups', $survey->getShippingMethodCode());
        self::assertSame($this->shippingMethods['ups']->getName(), $survey->getShippingMethodName());
    }

    public function testRefusesWhenTheChannelHasTheSurveySwitchedOff(): void
    {
        $this->configureChannel(enabled: false);

        self::assertNull($this->starter->start($this->order('ups')));
    }

    public function testRefusesWhenTheChannelHasNoConfigurationAtAll(): void
    {
        self::assertNull($this->starter->start($this->order('ups')));
    }

    public function testDoesNotCreateASecondSurveyForTheSameOrder(): void
    {
        $this->configureChannel();
        $order = $this->order('ups');

        $first = $this->starter->start($order);
        $second = $this->starter->start($order);

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertSame(1, $this->countSurveys());
    }

    public function testRestartsTheCountdownForASurveyStillWaitingToBeSent(): void
    {
        $this->configureChannel();
        $order = $this->order('ups');

        $survey = $this->starter->start($order);
        self::assertNotNull($survey);

        $survey->setQualifiedAt(new \DateTimeImmutable('-3 days'));
        $this->entityManager->flush();

        $this->starter->start($order);

        $qualifiedAt = $survey->getQualifiedAt();
        self::assertNotNull($qualifiedAt);
        self::assertGreaterThan(
            new \DateTimeImmutable('-1 hour'),
            $qualifiedAt,
            'A pending survey should have its countdown restarted.',
        );
    }

    public function testLeavesTheCountdownAloneOnceTheSurveyHasBeenSent(): void
    {
        $this->configureChannel();
        $order = $this->order('ups');

        $survey = $this->starter->start($order);
        self::assertNotNull($survey);

        $sentAt = new \DateTimeImmutable('-3 days');
        $survey->setStatus(SurveyStatus::Sent);
        $survey->setQualifiedAt($sentAt);
        $this->entityManager->flush();

        $this->starter->start($order);

        self::assertEquals($sentAt, $survey->getQualifiedAt());
    }

    public function testStartsASurveyEvenWhenTheOrderHasNoCustomerToWriteTo(): void
    {
        $this->configureChannel();

        $order = $this->order('ups');
        $order->setCustomer(null);
        $this->entityManager->flush();

        $survey = $this->starter->start($order);

        self::assertNotNull($survey, 'A missing recipient is settled when the survey is sent, not here.');
        self::assertNull($survey->getCustomer());
    }

    private function configureChannel(bool $enabled = true): void
    {
        $configuration = new Configuration();
        $configuration->setChannelCode('FASHION_WEB');
        $configuration->setEnabled($enabled);

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();
    }

    private function order(string $shippingMethodCode): OrderInterface
    {
        $order = $this->orders->createOrder(null, $this->channel, $this->shippingMethods[$shippingMethodCode]);
        $this->entityManager->flush();

        return $order;
    }

    private function countSurveys(): int
    {
        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(s.id) FROM Cyfrowe\SyliusNpsPlugin\Entity\Survey s')
            ->getSingleScalarResult()
        ;
    }
}
