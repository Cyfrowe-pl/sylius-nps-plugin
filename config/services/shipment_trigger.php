<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Cyfrowe\SyliusNpsPlugin\Bridge\Shipment\ShipmentTrigger;
use Cyfrowe\SyliusNpsPlugin\Bridge\Shipment\WorkflowShipmentListener;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyfrowe_nps.bridge.shipment_trigger', ShipmentTrigger::class)
        ->public()
        ->args([
            service('cyfrowe_nps.survey_starter'),
        ])
    ;

    $services->set('cyfrowe_nps.bridge.shipment_trigger.workflow_listener', WorkflowShipmentListener::class)
        ->args([
            service('cyfrowe_nps.bridge.shipment_trigger'),
        ])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_shipment.completed.ship'])
    ;
};
