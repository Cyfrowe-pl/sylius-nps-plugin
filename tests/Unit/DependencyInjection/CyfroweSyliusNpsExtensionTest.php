<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\DependencyInjection;

use Cyfrowe\SyliusNpsPlugin\DependencyInjection\CyfroweSyliusNpsExtension;
use Cyfrowe\SyliusNpsPlugin\Message\SendSurvey;
use Doctrine\Bundle\MigrationsBundle\DependencyInjection\DoctrineMigrationsExtension;
use PHPUnit\Framework\TestCase;
use SyliusLabs\DoctrineMigrationsExtraBundle\DependencyInjection\SyliusLabsDoctrineMigrationsExtraExtension;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class CyfroweSyliusNpsExtensionTest extends TestCase
{
    public function testTheCsvExportDefaultsToRfc4180(): void
    {
        $container = $this->containerFor([]);

        self::assertSame(',', $container->getParameter('cyfrowe_nps.export.csv.delimiter'));
        self::assertSame('"', $container->getParameter('cyfrowe_nps.export.csv.enclosure'));
        self::assertFalse($container->getParameter('cyfrowe_nps.export.csv.bom'));
    }

    public function testTheCsvExportCanBeSetUpForExcel(): void
    {
        $container = $this->containerFor(['export' => ['csv' => ['delimiter' => ';', 'bom' => true]]]);

        self::assertSame(';', $container->getParameter('cyfrowe_nps.export.csv.delimiter'));
        self::assertTrue($container->getParameter('cyfrowe_nps.export.csv.bom'));
        self::assertSame('"', $container->getParameter('cyfrowe_nps.export.csv.enclosure'), 'An untouched setting keeps its default.');
    }

    /**
     * @dataProvider settingsThatMustBeOneCharacter
     */
    public function testASettingOfMoreThanOneCharacterIsRefusedWhileTheContainerIsBuilt(string $setting): void
    {
        // Refused here rather than halfway through a download, with the headers already sent and
        // a truncated file already saved.
        $this->expectException(InvalidConfigurationException::class);

        $this->containerFor(['export' => ['csv' => [$setting => ';;']]]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function settingsThatMustBeOneCharacter(): iterable
    {
        yield 'delimiter' => ['delimiter'];
        yield 'enclosure' => ['enclosure'];
        yield 'decimal separator' => ['decimal_separator'];
    }

    public function testASeparatorThatIsAlsoTheQuoteCharacterIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->containerFor(['export' => ['csv' => ['delimiter' => '"']]]);
    }

    public function testTheShipmentTriggerIsRegisteredByDefault(): void
    {
        $container = $this->containerFor([]);

        self::assertTrue($container->has('cyfrowe_nps.bridge.shipment_trigger'));
        self::assertTrue($container->has('cyfrowe_nps.bridge.shipment_trigger.workflow_listener'));
    }

    public function testTheShipmentTriggerStaysOutOfTheContainerWhenSwitchedOff(): void
    {
        $container = $this->containerFor(['shipment_trigger' => false]);

        self::assertFalse($container->has('cyfrowe_nps.bridge.shipment_trigger'));
        self::assertFalse($container->has('cyfrowe_nps.bridge.shipment_trigger.workflow_listener'));
    }

    public function testThePublicEntryPointIsAlwaysAvailable(): void
    {
        self::assertTrue($this->containerFor([])->has('cyfrowe_nps.survey_starter'));
    }

    public function testItHandsWinzouACallbackByDefault(): void
    {
        $container = $this->preparedContainer([]);

        $config = $container->getExtensionConfig('winzou_state_machine');

        self::assertNotSame([], $config, 'The winzou adapter was left without a callback.');

        $callbacks = $config[0]['sylius_shipment']['callbacks']['after'] ?? [];
        self::assertCount(1, $callbacks);

        $callback = reset($callbacks);
        self::assertSame(['ship'], $callback['on']);
        self::assertSame(['@cyfrowe_nps.bridge.shipment_trigger', 'onShipmentShipped'], $callback['do']);
        self::assertSame(['object'], $callback['args']);
    }

    public function testItLeavesWinzouAloneWhenTheTriggerIsOff(): void
    {
        $container = $this->preparedContainer(['shipment_trigger' => false]);

        self::assertSame([], $container->getExtensionConfig('winzou_state_machine'));
    }

    public function testItLeavesWinzouAloneWhenTheBundleIsNotInstalled(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('cyfrowe_sylius_nps', ['shipment_trigger' => true]);

        (new CyfroweSyliusNpsExtension())->prepend($container);

        self::assertFalse($container->hasExtension('winzou_state_machine'));
    }

    /** @param array<string, mixed> $config */
    private function preparedContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new WinzouStateMachineExtension());
        $container->prependExtensionConfig('cyfrowe_sylius_nps', $config);

        (new CyfroweSyliusNpsExtension())->prepend($container);

        return $container;
    }

    public function testTheSurveyMessageIsLeftToTheApplicationToRoute(): void
    {
        $container = $this->preparedContainer([]);

        foreach ($container->getExtensionConfig('framework') as $config) {
            self::assertArrayNotHasKey('messenger', $config);
        }
    }

    public function testTheSurveyMessageCanBeGivenAQueueOfItsOwn(): void
    {
        $container = $this->preparedContainer(['messenger' => ['transport' => 'nps']]);

        $routing = [];

        foreach ($container->getExtensionConfig('framework') as $config) {
            $routing += $config['messenger']['routing'] ?? [];
        }

        self::assertSame(['nps'], array_values($routing));
        self::assertSame([SendSurvey::class], array_keys($routing));
    }

    public function testTheSurveysAreDispatchedOnTheDefaultBusByDefault(): void
    {
        $bus = $this->containerFor([])->getDefinition('cyfrowe_nps.command.send_surveys')->getArgument(2);

        self::assertEquals(new Reference('messenger.default_bus'), $bus);
    }

    public function testTheSurveysCanBeDispatchedOnABusOfTheShopsChoice(): void
    {
        $bus = $this->containerFor(['messenger' => ['bus' => 'app.nps.bus']])
            ->getDefinition('cyfrowe_nps.command.send_surveys')
            ->getArgument(2);

        self::assertEquals(new Reference('app.nps.bus'), $bus);
    }

    public function testTheEmailAndTheSurveyPagesUseTheStockSyliusLayoutsByDefault(): void
    {
        $container = $this->containerFor([]);

        self::assertSame('@SyliusShop/Email/layout.html.twig', $container->getParameter('cyfrowe_nps.email.layout'));
        self::assertSame('@SyliusShop/layout.html.twig', $container->getParameter('cyfrowe_nps.shop.layout'));
    }

    public function testAShopCanWrapBothInItsOwnLayout(): void
    {
        $container = $this->containerFor([
            'email' => ['layout' => '@App/Email/branded.html.twig'],
            'shop' => ['layout' => '@App/storefront.html.twig'],
        ]);

        self::assertSame('@App/Email/branded.html.twig', $container->getParameter('cyfrowe_nps.email.layout'));
        self::assertSame('@App/storefront.html.twig', $container->getParameter('cyfrowe_nps.shop.layout'));
    }

    public function testTheMigrationsKeepTheirPathNextToAnotherPackageUsingTheDefaultNamespace(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineMigrationsExtension());
        $container->registerExtension(new SyliusLabsDoctrineMigrationsExtraExtension());
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => ['DoctrineMigrations' => '@AnotherPlugin/migrations'],
        ]);

        (new CyfroweSyliusNpsExtension())->prepend($container);

        $paths = array_replace(...array_map(
            static fn (array $config): array => $config['migrations_paths'] ?? [],
            $container->getExtensionConfig('doctrine_migrations'),
        ));

        self::assertSame('@CyfroweSyliusNpsPlugin/migrations', $paths['Cyfrowe\\SyliusNpsPlugin\\Migrations'] ?? null);
        self::assertSame('@AnotherPlugin/migrations', $paths['DoctrineMigrations'] ?? null);
    }

    public function testItShipsADefaultListOfScannerSignatures(): void
    {
        $agents = $this->containerFor([])->getParameter('cyfrowe_nps.shop.bot_user_agents');

        self::assertIsArray($agents);
        self::assertContains('mimecast', $agents, 'A shop should get something usable without configuring anything.');
    }

    public function testTheScannerSignaturesCanBeReplaced(): void
    {
        $container = $this->containerFor(['shop' => ['bot_user_agents' => ['acme-mail-filter']]]);

        self::assertSame(['acme-mail-filter'], $container->getParameter('cyfrowe_nps.shop.bot_user_agents'));
    }

    public function testTheLockStoreDefaultsToAFileOnThisMachine(): void
    {
        self::assertSame('flock', $this->containerFor([])->getParameter('cyfrowe_nps.lock.dsn'));
    }

    public function testTheLockStoreCanBePointedAtASharedOne(): void
    {
        $container = $this->containerFor(['lock' => ['dsn' => 'redis://localhost']]);

        self::assertSame('redis://localhost', $container->getParameter('cyfrowe_nps.lock.dsn'));
    }

    /** @param array<string, mixed> $config */
    private function containerFor(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new CyfroweSyliusNpsExtension())->load([$config], $container);

        return $container;
    }
}
