<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\Bridge\Shipment;

use Cyfrowe\SyliusNpsPlugin\Bridge\Shipment\ShipmentTrigger;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;

final class ShipmentTriggerTest extends TestCase
{
    public function testStartsTheSurveyWhenTheOnlyShipmentIsShipped(): void
    {
        $order = $this->orderWithShipments(ShipmentInterface::STATE_SHIPPED);

        self::assertSame([$order], $this->startedFor($order));
    }

    public function testWaitsForTheSecondShipment(): void
    {
        $order = $this->orderWithShipments(ShipmentInterface::STATE_SHIPPED, ShipmentInterface::STATE_READY);

        self::assertSame([], $this->startedFor($order));
    }

    public function testStartsTheSurveyOnceTheSecondShipmentIsShipped(): void
    {
        $order = $this->orderWithShipments(ShipmentInterface::STATE_SHIPPED, ShipmentInterface::STATE_SHIPPED);

        self::assertSame([$order], $this->startedFor($order));
    }

    public function testACancelledShipmentDoesNotHoldTheOrderBack(): void
    {
        $order = $this->orderWithShipments(ShipmentInterface::STATE_SHIPPED, ShipmentInterface::STATE_CANCELLED);

        self::assertSame([$order], $this->startedFor($order));
    }

    public function testAnOrderWithOnlyCancelledShipmentsIsNotSurveyed(): void
    {
        $order = $this->orderWithShipments(ShipmentInterface::STATE_CANCELLED);

        self::assertSame([], $this->startedFor($order));
    }

    public function testAShipmentWithoutAnOrderIsIgnored(): void
    {
        $shipment = new Shipment();
        $shipment->setState(ShipmentInterface::STATE_SHIPPED);

        $starter = $this->recordingStarter();
        (new ShipmentTrigger($starter))->onShipmentShipped($shipment);

        self::assertSame([], $starter->started);
    }

    /** @return list<OrderInterface> */
    private function startedFor(OrderInterface $order): array
    {
        $starter = $this->recordingStarter();
        $trigger = new ShipmentTrigger($starter);

        $shipment = $order->getShipments()->first();
        assert($shipment instanceof ShipmentInterface);

        $trigger->onShipmentShipped($shipment);

        return $starter->started;
    }

    private function orderWithShipments(string ...$states): OrderInterface
    {
        $order = new Order();

        foreach ($states as $state) {
            $shipment = new Shipment();
            $shipment->setState($state);
            $order->addShipment($shipment);
        }

        return $order;
    }

    private function recordingStarter(): RecordingSurveyStarter
    {
        return new RecordingSurveyStarter();
    }
}
