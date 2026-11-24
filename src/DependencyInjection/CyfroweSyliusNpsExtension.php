<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\DependencyInjection;

use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertData;
use Cyfrowe\SyliusNpsPlugin\Mailer\SurveySender;
use Cyfrowe\SyliusNpsPlugin\Message\SendSurvey;
use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;

final class CyfroweSyliusNpsExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    private const FILTER_TEMPLATE = '@CyfroweSyliusNpsPlugin/admin/survey/grid/filter/generic.html.twig';

    private const EXPORT_ACTION_TEMPLATE = '@CyfroweSyliusNpsPlugin/admin/survey/grid/action/export.html.twig';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.php');
        $loader->load('services/shop.php');
        $loader->load('services/sending.php');
        $loader->load('services/admin_list.php');
        $loader->load('services/export.php');

        $config = $this->resolve($configs);

        $container->setParameter('cyfrowe_nps.email.layout', $config['email']['layout']);
        $container->setParameter('cyfrowe_nps.lock.dsn', $config['lock']['dsn']);
        $container->setParameter('cyfrowe_nps.shop.layout', $config['shop']['layout']);
        $container->setParameter('cyfrowe_nps.shop.bot_user_agents', $config['shop']['bot_user_agents']);
        $container->setParameter('cyfrowe_nps.export.csv.delimiter', $config['export']['csv']['delimiter']);
        $container->setParameter('cyfrowe_nps.export.csv.enclosure', $config['export']['csv']['enclosure']);
        $container->setParameter('cyfrowe_nps.export.csv.decimal_separator', $config['export']['csv']['decimal_separator']);
        $container->setParameter('cyfrowe_nps.export.csv.bom', $config['export']['csv']['bom']);

        $container->getDefinition('cyfrowe_nps.command.send_surveys')
            ->replaceArgument(2, new Reference($config['messenger']['bus']));

        if ($config['shipment_trigger']) {
            $loader->load('services/shipment_trigger.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);

        $resourcesFile = __DIR__ . '/../../config/app/config.yaml';

        /** @var array{sylius_resource: array<string, mixed>} $resources */
        $resources = Yaml::parseFile($resourcesFile);
        $container->prependExtensionConfig('sylius_resource', $resources['sylius_resource']);
        $container->addResource(new FileResource($resourcesFile));

        // The figures belong above the list, between its header and the grid itself. Sylius renders
        // the index page from UI events, so a block of our own slots in without taking over the
        // template - and the block is handed the index context, grid view included.
        $container->prependExtensionConfig('sylius_ui', [
            'events' => [
                'cyfrowe_nps.admin.survey.index' => [
                    'blocks' => [
                        'nps_summary' => [
                            'template' => '@CyfroweSyliusNpsPlugin/admin/survey/_summary.html.twig',
                            'priority' => 12,
                        ],
                    ],
                ],
            ],
        ]);

        // Sylius maps filter templates by type name, so the types this package adds need one.
        $container->prependExtensionConfig('sylius_grid', [
            'templates' => [
                'filter' => [
                    'cyfrowe_nps_score_range' => self::FILTER_TEMPLATE,
                    'cyfrowe_nps_score_category' => self::FILTER_TEMPLATE,
                    'cyfrowe_nps_distinct_value' => self::FILTER_TEMPLATE,
                    'cyfrowe_nps_enum' => self::FILTER_TEMPLATE,
                    'cyfrowe_nps_presence' => self::FILTER_TEMPLATE,
                ],
                'action' => [
                    'cyfrowe_nps_export' => self::EXPORT_ACTION_TEMPLATE,
                ],
            ],
        ]);

        foreach (['configuration', 'survey'] as $grid) {
            $file = sprintf('%s/../../config/grids/admin/%s.yaml', __DIR__, $grid);

            /** @var array{sylius_grid: array<string, mixed>} $definition */
            $definition = Yaml::parseFile($file);
            $container->prependExtensionConfig('sylius_grid', $definition['sylius_grid']);

            // Read by hand rather than through a loader, so the container has to be told the file
            // is one of its sources - as with the resource definitions above. Without this a change
            // to a grid leaves the cached container untouched, and the old definition goes on being
            // served.
            $container->addResource(new FileResource($file));
        }

        $container->prependExtensionConfig('twig', [
            'form_themes' => ['@CyfroweSyliusNpsPlugin/admin/form/theme.html.twig'],
        ]);

        $container->prependExtensionConfig('framework', [
            'validation' => [
                'mapping' => [
                    'paths' => [__DIR__ . '/../../config/validation'],
                ],
            ],
        ]);

        $container->prependExtensionConfig('sylius_mailer', [
            'emails' => [
                SurveySender::EMAIL_CODE => [
                    'template' => '@CyfroweSyliusNpsPlugin/email/survey.html.twig',
                ],
                CriticalAlertData::EMAIL_CODE => [
                    'template' => '@CyfroweSyliusNpsPlugin/email/critical_alert.html.twig',
                ],
            ],
        ]);

        $this->prependShipmentTriggerCallback($container);
        $this->prependMessengerRouting($container);
    }

    /**
     * Left unset, the message is routed by the application like any other. A shop that wants the
     * surveys on a queue of their own names it and nothing else has to change.
     */
    private function prependMessengerRouting(ContainerBuilder $container): void
    {
        $transport = $this->resolve($container->getExtensionConfig('cyfrowe_sylius_nps'))['messenger']['transport'];

        if (null === $transport) {
            return;
        }

        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'routing' => [
                    SendSurvey::class => $transport,
                ],
            ],
        ]);
    }

    private function prependShipmentTriggerCallback(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('winzou_state_machine')) {
            return;
        }

        if (!$this->resolve($container->getExtensionConfig('cyfrowe_sylius_nps'))['shipment_trigger']) {
            return;
        }

        $container->prependExtensionConfig('winzou_state_machine', [
            'sylius_shipment' => [
                'callbacks' => [
                    'after' => [
                        'cyfrowe_nps_start_survey' => [
                            'on' => ['ship'],
                            'do' => ['@cyfrowe_nps.bridge.shipment_trigger', 'onShipmentShipped'],
                            'args' => ['object'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<array-key, mixed> $configs
     *
     * @return array{shipment_trigger: bool, email: array{layout: string}, messenger: array{transport: string|null, bus: string}, shop: array{layout: string, bot_user_agents: list<string>}, lock: array{dsn: string}, export: array{csv: array{delimiter: string, enclosure: string, decimal_separator: string, bom: bool}}}
     */
    private function resolve(array $configs): array
    {
        /** @var array{shipment_trigger: bool, email: array{layout: string}, messenger: array{transport: string|null, bus: string}, shop: array{layout: string, bot_user_agents: list<string>}, lock: array{dsn: string}, export: array{csv: array{delimiter: string, enclosure: string, decimal_separator: string, bom: bool}}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        return $config;
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Cyfrowe\SyliusNpsPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@CyfroweSyliusNpsPlugin/migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
