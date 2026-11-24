<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;

/**
 * Builds the few shop-side objects the survey needs, without dragging in fixtures.
 */
final class SurveyFactory
{
    private int $sequence = 0;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function createCustomer(): CustomerInterface
    {
        $customer = new Customer();
        $customer->setEmail(sprintf('customer-%d-%s@example.com', ++$this->sequence, uniqid()));

        $this->entityManager->persist($customer);

        return $customer;
    }

    public function createOrder(
        ?CustomerInterface $customer = null,
        ?ChannelInterface $channel = null,
        ?ShippingMethodInterface $shippingMethod = null,
    ): OrderInterface {
        $order = new Order();
        $order->setCurrencyCode('PLN');
        $order->setLocaleCode('pl_PL');
        $order->setNumber(sprintf('%06d', ++$this->sequence));
        $order->setCustomer($customer ?? $this->createCustomer());

        if (null !== $channel) {
            $order->setChannel($channel);
        }

        if (null !== $shippingMethod) {
            $shipment = new Shipment();
            $shipment->setState(ShipmentInterface::STATE_SHIPPED);
            $shipment->setMethod($shippingMethod);

            $order->addShipment($shipment);
            $this->entityManager->persist($shipment);
        }

        $this->entityManager->persist($order);

        return $order;
    }

    public function createSurvey(
        ?OrderInterface $order = null,
        ?CustomerInterface $customer = null,
        SurveyStatus $status = SurveyStatus::Pending,
        ?string $channelCode = 'TEST',
    ): Survey {
        if (null === $customer) {
            $customer = $order?->getCustomer() ?? $this->createCustomer();
            // Order::getCustomer() is typed against the base customer contract; the survey
            // stores the Core one, which is what the shop actually persists.
            assert($customer instanceof CustomerInterface);
        }

        $order ??= $this->createOrder($customer);

        $survey = new Survey();
        $survey->setOrder($order);
        $survey->setCustomer($customer);
        $survey->setOrderNumber(sprintf('ORD-%05d', ++$this->sequence));
        $survey->setStatus($status);
        $survey->setChannelCode($channelCode);
        $survey->setLocaleCode('pl_PL');
        $survey->setToken(bin2hex(random_bytes(8)));

        $this->entityManager->persist($survey);

        return $survey;
    }
}
