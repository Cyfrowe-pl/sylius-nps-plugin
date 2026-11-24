<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Cyfrowe\SyliusNpsPlugin\Controller\Admin\SaveHandlingAction;
use Cyfrowe\SyliusNpsPlugin\Controller\Admin\ShowResponseAction;
use Cyfrowe\SyliusNpsPlugin\Form\Type\Filter\DistinctValueFilterType;
use Cyfrowe\SyliusNpsPlugin\Form\Type\Filter\PresenceFilterType;
use Cyfrowe\SyliusNpsPlugin\Form\Type\Filter\ScoreCategoryFilterType;
use Cyfrowe\SyliusNpsPlugin\Form\Type\SurveyHandlingType;
use Cyfrowe\SyliusNpsPlugin\Grid\Filter\PresenceFilter;
use Cyfrowe\SyliusNpsPlugin\Grid\Filter\ScoreCategoryFilter;
use Cyfrowe\SyliusNpsPlugin\Grid\FilteredSurveys;
use Cyfrowe\SyliusNpsPlugin\Nps\NpsCalculator;
use Cyfrowe\SyliusNpsPlugin\Nps\SurveySummaryProvider;
use Cyfrowe\SyliusNpsPlugin\Twig\ScoreCategoryExtension;
use Cyfrowe\SyliusNpsPlugin\Twig\SurveySummaryExtension;
use Sylius\Bundle\GridBundle\Form\Type\Filter\EnumFilterType;
use Sylius\Bundle\GridBundle\Form\Type\Filter\NumericRangeFilterType;
use Sylius\Component\Grid\Filter\NumericRangeFilter;
use Sylius\Component\Grid\Filter\SelectFilter;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyfrowe_nps.grid_filter.score_category', ScoreCategoryFilter::class)
        ->tag('sylius.grid_filter', ['type' => 'cyfrowe_nps_score_category', 'form_type' => ScoreCategoryFilterType::class])
    ;

    $services->set('cyfrowe_nps.form.type.filter.score_category', ScoreCategoryFilterType::class)
        ->tag('form.type')
    ;

    // The applying side is Sylius' own select filter - a value in a list of values. Only the
    // choices are ours, read from the records rather than from the shop's catalogue.
    $services->set('cyfrowe_nps.grid_filter.distinct_value', SelectFilter::class)
        ->tag('sylius.grid_filter', ['type' => 'cyfrowe_nps_distinct_value', 'form_type' => DistinctValueFilterType::class])
    ;

    $services->set('cyfrowe_nps.form.type.filter.distinct_value', DistinctValueFilterType::class)
        ->args([
            service('cyfrowe_nps.repository.survey'),
        ])
        ->tag('form.type')
    ;

    // Sylius registers numeric_range and enum filters but ships no template for either, so
    // neither can be rendered as it stands. The logic and the form types are theirs; the type
    // names and the template are ours. Registering them under our own names rather than filling
    // the gaps in Sylius' template map keeps the plugin from changing how these filters render
    // in every other grid of the shop.
    $services->set('cyfrowe_nps.grid_filter.score_range', NumericRangeFilter::class)
        ->tag('sylius.grid_filter', ['type' => 'cyfrowe_nps_score_range', 'form_type' => NumericRangeFilterType::class])
    ;

    $services->set('cyfrowe_nps.grid_filter.enum', SelectFilter::class)
        ->tag('sylius.grid_filter', ['type' => 'cyfrowe_nps_enum', 'form_type' => EnumFilterType::class])
    ;

    $services->set('cyfrowe_nps.grid_filter.presence', PresenceFilter::class)
        ->tag('sylius.grid_filter', ['type' => 'cyfrowe_nps_presence', 'form_type' => PresenceFilterType::class])
    ;

    $services->set('cyfrowe_nps.form.type.filter.presence', PresenceFilterType::class)
        ->tag('form.type')
    ;

    $services->set('cyfrowe_nps.form.type.survey_handling', SurveyHandlingType::class)
        ->args([
            param('cyfrowe_nps.model.survey.class'),
        ])
        ->tag('form.type')
    ;

    $services->set('cyfrowe_nps.controller.admin.show_response', ShowResponseAction::class)
        ->public()
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('form.factory'),
            service('cyfrowe_nps.survey_wording'),
            service('twig'),
        ])
    ;

    $services->set('cyfrowe_nps.controller.admin.save_handling', SaveHandlingAction::class)
        ->public()
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('form.factory'),
            service('doctrine.orm.entity_manager'),
            service('router'),
            service('security.helper'),
        ])
    ;

    $services->set('cyfrowe_nps.twig.score_category', ScoreCategoryExtension::class)
        ->tag('twig.extension')
    ;

    $services->set('cyfrowe_nps.nps_calculator', NpsCalculator::class)
        ->public()
    ;

    $services->set('cyfrowe_nps.grid.filtered_surveys', FilteredSurveys::class)
        ->public()
        ->args([
            service('sylius.grid.provider'),
            service('sylius.grid.data_source_provider'),
            service('sylius.grid.filters_applicator'),
        ])
    ;

    $services->set('cyfrowe_nps.survey_summary_provider', SurveySummaryProvider::class)
        ->public()
        ->args([
            service('cyfrowe_nps.grid.filtered_surveys'),
            service('cyfrowe_nps.nps_calculator'),
        ])
    ;

    $services->set('cyfrowe_nps.twig.survey_summary', SurveySummaryExtension::class)
        ->args([
            service('cyfrowe_nps.survey_summary_provider'),
        ])
        ->tag('twig.extension')
    ;
};
