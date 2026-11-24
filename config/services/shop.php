<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Cyfrowe\SyliusNpsPlugin\Controller\Shop\ScoreAction;
use Cyfrowe\SyliusNpsPlugin\Controller\Shop\SurveyPageAction;
use Cyfrowe\SyliusNpsPlugin\Controller\Shop\SurveyPages;
use Cyfrowe\SyliusNpsPlugin\EventListener\SurveyResponseHeadersListener;
use Cyfrowe\SyliusNpsPlugin\Form\Type\SurveyAnswerType;
use Cyfrowe\SyliusNpsPlugin\Security\BotGuard;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyfrowe_nps.rate_limiter.survey_token', RateLimiterFactory::class)
        ->args([
            [
                'id' => 'cyfrowe_nps_survey_token',
                'policy' => 'fixed_window',
                'limit' => 5,
                'interval' => '1 minute',
            ],
            service('cyfrowe_nps.rate_limiter.storage'),
        ])
    ;

    $services->set('cyfrowe_nps.rate_limiter.survey_ip', RateLimiterFactory::class)
        ->args([
            [
                'id' => 'cyfrowe_nps_survey_ip',
                'policy' => 'fixed_window',
                'limit' => 60,
                'interval' => '1 minute',
            ],
            service('cyfrowe_nps.rate_limiter.storage'),
        ])
    ;

    $services->set('cyfrowe_nps.rate_limiter.storage', CacheStorage::class)
        ->args([
            service('cache.app'),
        ])
    ;

    $services->set('cyfrowe_nps.security.bot_guard', BotGuard::class)
        ->args([
            service('cyfrowe_nps.rate_limiter.survey_token'),
            service('cyfrowe_nps.rate_limiter.survey_ip'),
            param('cyfrowe_nps.shop.bot_user_agents'),
        ])
    ;

    $services->set('cyfrowe_nps.controller.shop.survey_pages', SurveyPages::class)
        ->args([
            service('twig'),
            service('cyfrowe_nps.survey_wording'),
            param('cyfrowe_nps.shop.layout'),
        ])
    ;

    $services->set('cyfrowe_nps.controller.shop.score', ScoreAction::class)
        ->public()
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('doctrine.orm.entity_manager'),
            service('cyfrowe_nps.controller.shop.survey_pages'),
            service('cyfrowe_nps.security.bot_guard'),
            service('clock'),
            service('router'),
        ])
    ;

    $services->set('cyfrowe_nps.controller.shop.survey', SurveyPageAction::class)
        ->public()
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('doctrine.orm.entity_manager'),
            service('form.factory'),
            service('cyfrowe_nps.controller.shop.survey_pages'),
            service('clock'),
        ])
    ;

    $services->set('cyfrowe_nps.form.type.survey_answer', SurveyAnswerType::class)
        ->args([
            param('cyfrowe_nps.model.survey.class'),
        ])
        ->tag('form.type')
    ;

    $services->set('cyfrowe_nps.listener.survey_response_headers', SurveyResponseHeadersListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.response'])
    ;
};
