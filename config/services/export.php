<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Cyfrowe\SyliusNpsPlugin\Controller\Admin\ExportCsvAction;
use Cyfrowe\SyliusNpsPlugin\Export\SurveyCsvExporter;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyfrowe_nps.export.survey_csv', SurveyCsvExporter::class)
        ->public()
        ->args([
            service('cyfrowe_nps.grid.filtered_surveys'),
            service('translator'),
            service('clock'),
            param('cyfrowe_nps.export.csv.delimiter'),
            param('cyfrowe_nps.export.csv.enclosure'),
            param('cyfrowe_nps.export.csv.decimal_separator'),
            param('cyfrowe_nps.export.csv.bom'),
        ])
    ;

    $services->set('cyfrowe_nps.controller.admin.export_csv', ExportCsvAction::class)
        ->public()
        ->args([
            service('cyfrowe_nps.export.survey_csv'),
        ])
    ;
};
