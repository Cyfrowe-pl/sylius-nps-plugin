<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Survey;

use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface as CoreCustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Symfony\Component\Clock\ClockInterface;

final class SurveyStarter implements SurveyStarterInterface
{
    public function __construct(
        private readonly SurveyRepositoryInterface $surveyRepository,
        private readonly RepositoryInterface $configurationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function start(OrderInterface $order): ?SurveyInterface
    {
        $channelCode = $order->getChannel()?->getCode();

        if (null === $channelCode) {
            return null;
        }

        $configuration = $this->configurationRepository->findOneBy(['channelCode' => $channelCode]);

        if (!$configuration instanceof ConfigurationInterface || !$configuration->isEnabled()) {
            return null;
        }

        $existing = $this->surveyRepository->findOneByOrder($order);

        if (null !== $existing) {
            return $this->refresh($existing);
        }

        $survey = $this->create($order, $channelCode);

        $this->entityManager->persist($survey);
        $this->entityManager->flush();

        return $survey;
    }

    /**
     * A survey still waiting to be sent has its countdown restarted, so a parcel sent after the
     * first one does not leave the customer rating an incomplete order.
     */
    private function refresh(SurveyInterface $survey): SurveyInterface
    {
        if (SurveyStatus::Pending !== $survey->getStatus()) {
            return $survey;
        }

        $survey->setQualifiedAt($this->clock->now());
        $this->entityManager->flush();

        return $survey;
    }

    private function create(OrderInterface $order, string $channelCode): SurveyInterface
    {
        $survey = new Survey();

        $survey->setToken(bin2hex(random_bytes(16)));
        $survey->setStatus(SurveyStatus::Pending);
        $survey->setQualifiedAt($this->clock->now());

        $survey->setOrder($order);
        $survey->setOrderNumber($order->getNumber());
        $customer = $order->getCustomer();
        $survey->setCustomer($customer instanceof CoreCustomerInterface ? $customer : null);
        $survey->setOrderTotal($order->getTotal());
        $survey->setCurrencyCode($order->getCurrencyCode());
        $survey->setChannelCode($channelCode);
        $survey->setLocaleCode($order->getLocaleCode());

        $shippingMethod = $this->shippingMethodOf($order);
        $survey->setShippingMethodCode($shippingMethod?->getCode());
        $survey->setShippingMethodName($shippingMethod?->getName());

        return $survey;
    }

    private function shippingMethodOf(OrderInterface $order): ?ShippingMethodInterface
    {
        foreach ($order->getShipments() as $shipment) {
            $method = $shipment->getMethod();

            if (null !== $method) {
                return $method;
            }
        }

        return null;
    }
}
