<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tests\Cyfrowe\SyliusNpsPlugin\Behat\Context\Ui\Shop\SurveyContext;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->public()
    ;

    $services->set(SurveyContext::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('test.client'),
            service('cache.app'),
        ])
    ;
};
