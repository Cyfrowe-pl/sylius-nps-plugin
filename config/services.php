<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Cyfrowe\SyliusNpsPlugin\Form\Type\ChannelCodeChoiceType;
use Cyfrowe\SyliusNpsPlugin\Form\Type\ConfigurationTranslationType;
use Cyfrowe\SyliusNpsPlugin\Form\Type\ConfigurationType;
use Cyfrowe\SyliusNpsPlugin\Menu\AdminMenuBuilder;
use Cyfrowe\SyliusNpsPlugin\Survey\SurveyStarter;
use Cyfrowe\SyliusNpsPlugin\Survey\SurveyStarterInterface;
use Cyfrowe\SyliusNpsPlugin\Survey\SurveyWording;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyfrowe_nps.form.type.configuration', ConfigurationType::class)
        ->args([
            param('cyfrowe_nps.model.channel_configuration.class'),
            ['sylius'],
            service('router'),
        ])
        ->tag('form.type')
    ;

    $services->set('cyfrowe_nps.form.type.configuration_translation', ConfigurationTranslationType::class)
        ->args([
            param('cyfrowe_nps.model.channel_configuration_translation.class'),
            ['sylius'],
        ])
        ->tag('form.type')
    ;

    $services->set('cyfrowe_nps.form.type.channel_code_choice', ChannelCodeChoiceType::class)
        ->args([
            service('sylius.repository.channel'),
        ])
        ->tag('form.type')
    ;

    $services->set('cyfrowe_nps.survey_starter', SurveyStarter::class)
        ->public()
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('cyfrowe_nps.repository.channel_configuration'),
            service('doctrine.orm.entity_manager'),
            service('clock'),
        ])
    ;

    $services->alias(SurveyStarterInterface::class, 'cyfrowe_nps.survey_starter')
        ->public()
    ;

    $services->set('cyfrowe_nps.survey_wording', SurveyWording::class)
        ->args([
            service('cyfrowe_nps.repository.channel_configuration'),
        ])
    ;

    $services->set('cyfrowe_nps.menu.admin', AdminMenuBuilder::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main', 'method' => 'buildMenu'])
    ;
};
