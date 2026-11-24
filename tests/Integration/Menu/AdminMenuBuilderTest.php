<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Menu;

use Cyfrowe\SyliusNpsPlugin\Menu\AdminMenuBuilder;
use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;

final class AdminMenuBuilderTest extends IntegrationTestCase
{
    public function testEveryItemItAddsPointsAtARouteTheApplicationHas(): void
    {
        $factory = self::getContainer()->get('knp_menu.factory');
        assert($factory instanceof FactoryInterface);

        $menu = $factory->createItem('root');

        (new AdminMenuBuilder())->buildMenu(new MenuBuilderEvent($factory, $menu));

        $routed = array_filter(
            $this->flatten($menu),
            static fn (ItemInterface $item): bool => [] !== ($item->getExtra('routes') ?? []),
        );

        self::assertNotSame([], $routed, 'The builder added no menu item pointing at a route.');

        foreach ($routed as $item) {
            self::assertNotNull(
                $item->getUri(),
                sprintf('Menu item "%s" declares a route but has no URI.', $item->getName()),
            );
        }
    }

    /** @return list<ItemInterface> */
    private function flatten(ItemInterface $item): array
    {
        $items = [];

        foreach ($item->getChildren() as $child) {
            $items[] = $child;
            $items = array_merge($items, $this->flatten($child));
        }

        return $items;
    }
}
