<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * Adds the NPS section to the admin menu through the UI bundle's event, so no core template
 * is touched.
 */
final class AdminMenuBuilder
{
    public function buildMenu(MenuBuilderEvent $event): void
    {
        $nps = $event->getMenu()
            ->addChild('cyfrowe_nps')
            ->setLabel('cyfrowe_nps.ui.nps')
        ;

        $nps
            ->addChild('cyfrowe_nps_responses', [
                'route' => 'cyfrowe_nps_admin_survey_index',
            ])
            ->setLabel('cyfrowe_nps.ui.responses')
            ->setLabelAttribute('icon', 'comments')
        ;

        $nps
            ->addChild('cyfrowe_nps_configuration', [
                'route' => 'cyfrowe_nps_admin_channel_configuration_index',
            ])
            ->setLabel('cyfrowe_nps.ui.settings')
            ->setLabelAttribute('icon', 'cog')
        ;
    }
}
