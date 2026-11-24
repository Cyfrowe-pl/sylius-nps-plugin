<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Bridge\Shipment;

use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Workflow\Event\Event;

/**
 * Entry point for shops running the Symfony Workflow adapter. The winzou adapter reaches
 * ShipmentTrigger through a state machine callback instead.
 */
final class WorkflowShipmentListener
{
    public function __construct(private readonly ShipmentTrigger $trigger)
    {
    }

    public function __invoke(Event $event): void
    {
        $shipment = $event->getSubject();

        if ($shipment instanceof ShipmentInterface) {
            $this->trigger->onShipmentShipped($shipment);
        }
    }
}
