<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Bridge\Shipment;

use Cyfrowe\SyliusNpsPlugin\Survey\SurveyStarterInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * A reference trigger for shops that have no delivery signal of their own: it starts the survey
 * once every shipment of an order that still counts has been shipped. On by default, so a stock
 * Sylius install surveys without any code; a shop with its own trigger switches it off.
 */
final class ShipmentTrigger
{
    public function __construct(private readonly SurveyStarterInterface $surveyStarter)
    {
    }

    public function onShipmentShipped(ShipmentInterface $shipment): void
    {
        $order = $shipment->getOrder();

        if (!$order instanceof OrderInterface) {
            return;
        }

        if (!$this->everyShipmentThatCountsHasBeenShipped($order)) {
            return;
        }

        $this->surveyStarter->start($order);
    }

    /**
     * Cancelled shipments are skipped on purpose. Sylius' own order shipping state compares the
     * shipped count against all shipments, so an order with one shipped and one cancelled parcel
     * never reaches the shipped state and would silently never be surveyed.
     */
    private function everyShipmentThatCountsHasBeenShipped(OrderInterface $order): bool
    {
        $counted = 0;

        foreach ($order->getShipments() as $shipment) {
            if (ShipmentInterface::STATE_CANCELLED === $shipment->getState()) {
                continue;
            }

            ++$counted;

            if (ShipmentInterface::STATE_SHIPPED !== $shipment->getState()) {
                return false;
            }
        }

        return $counted > 0;
    }
}
