<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Cyfrowe\SyliusNpsPlugin\Command\NotifyCriticalCommand;
use Cyfrowe\SyliusNpsPlugin\Command\SendSurveysCommand;
use Cyfrowe\SyliusNpsPlugin\Controller\Admin\SendTestEmailAction;
use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertData;
use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertSender;
use Cyfrowe\SyliusNpsPlugin\Mailer\SurveyEmailData;
use Cyfrowe\SyliusNpsPlugin\Mailer\SurveySender;
use Cyfrowe\SyliusNpsPlugin\Mailer\TestEmailSender;
use Cyfrowe\SyliusNpsPlugin\MessageHandler\SendSurveyHandler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\StoreFactory;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyfrowe_nps.mailer.critical_alert_data', CriticalAlertData::class)
        ->args([
            param('cyfrowe_nps.email.layout'),
        ])
    ;

    $services->set('cyfrowe_nps.mailer.critical_alert_sender', CriticalAlertSender::class)
        ->public()
        ->args([
            service('sylius.repository.channel'),
            service('sylius.email_sender'),
            service('cyfrowe_nps.mailer.critical_alert_data'),
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service('logger'),
            param('kernel.default_locale'),
        ])
    ;

    $services->set('cyfrowe_nps.mailer.email_data', SurveyEmailData::class)
        ->args([
            param('cyfrowe_nps.email.layout'),
        ])
    ;

    $services->set('cyfrowe_nps.mailer.test_email_sender', TestEmailSender::class)
        ->public()
        ->args([
            service('sylius.repository.channel'),
            service('sylius.email_sender'),
            service('cyfrowe_nps.mailer.email_data'),
        ])
    ;

    $services->set('cyfrowe_nps.mailer.survey_sender', SurveySender::class)
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('cyfrowe_nps.repository.channel_configuration'),
            service('sylius.repository.channel'),
            service('sylius.email_sender'),
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service('cyfrowe_nps.mailer.email_data'),
            service('logger'),
        ])
    ;

    $services->set('cyfrowe_nps.message_handler.send_survey', SendSurveyHandler::class)
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('cyfrowe_nps.mailer.survey_sender'),
        ])
        ->tag('messenger.message_handler')
    ;

    // The package brings its own lock factory rather than relying on framework.lock being
    // configured: a shop that never set it up would otherwise get a missing service the first time
    // the command runs. The store comes from cyfrowe_sylius_nps.lock.dsn.
    $services->set('cyfrowe_nps.lock.store', PersistingStoreInterface::class)
        ->factory([StoreFactory::class, 'createStore'])
        ->args([
            param('cyfrowe_nps.lock.dsn'),
        ])
    ;

    $services->set('cyfrowe_nps.lock.factory', LockFactory::class)
        ->args([
            service('cyfrowe_nps.lock.store'),
        ])
    ;

    $services->set('cyfrowe_nps.controller.admin.send_test_email', SendTestEmailAction::class)
        ->public()
        ->args([
            service('form.factory'),
            service('cyfrowe_nps.mailer.test_email_sender'),
            service('router'),
        ])
    ;

    // The bus (argument 2) is replaced with the configured one by the extension.
    $services->set('cyfrowe_nps.command.send_surveys', SendSurveysCommand::class)
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('cyfrowe_nps.repository.channel_configuration'),
            service('messenger.default_bus'),
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service('cyfrowe_nps.lock.factory'),
            service('logger'),
        ])
        ->tag('console.command')
    ;

    $services->set('cyfrowe_nps.command.notify_critical', NotifyCriticalCommand::class)
        ->args([
            service('cyfrowe_nps.repository.survey'),
            service('cyfrowe_nps.repository.channel_configuration'),
            service('cyfrowe_nps.mailer.critical_alert_sender'),
            service('clock'),
            service('cyfrowe_nps.lock.factory'),
        ])
        ->tag('console.command')
    ;
};
