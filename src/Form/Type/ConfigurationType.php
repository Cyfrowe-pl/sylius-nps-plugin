<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Form\Type;

use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Sylius\Bundle\ResourceBundle\Form\Type\ResourceTranslationsType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ConfigurationType extends AbstractResourceType
{
    public function __construct(string $dataClass, array $validationGroups, private readonly UrlGeneratorInterface $urlGenerator)
    {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channelCode', ChannelCodeChoiceType::class, [
                'label' => 'cyfrowe_nps.form.configuration.channel',
                'help' => 'cyfrowe_nps.form.configuration.channel_help',
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'cyfrowe_nps.form.configuration.enabled',
                'required' => false,
            ])
            ->add('delayDays', IntegerType::class, [
                'label' => 'cyfrowe_nps.form.configuration.delay_days',
                'help' => 'cyfrowe_nps.form.configuration.delay_days_help',
            ])
            ->add('cooldownDays', IntegerType::class, [
                'label' => 'cyfrowe_nps.form.configuration.cooldown_days',
                'help' => 'cyfrowe_nps.form.configuration.cooldown_days_help',
            ])
            ->add('sendHourFrom', IntegerType::class, [
                'label' => 'cyfrowe_nps.form.configuration.send_hour_from',
                'help' => 'cyfrowe_nps.form.configuration.send_hour_from_help',
            ])
            ->add('sendHourTo', IntegerType::class, [
                'label' => 'cyfrowe_nps.form.configuration.send_hour_to',
                'help' => 'cyfrowe_nps.form.configuration.send_hour_to_help',
            ])
            ->add('linkValidityDays', IntegerType::class, [
                'label' => 'cyfrowe_nps.form.configuration.link_validity_days',
                'help' => 'cyfrowe_nps.form.configuration.link_validity_days_help',
            ])
            ->add('notifyEnabled', CheckboxType::class, [
                'label' => 'cyfrowe_nps.form.configuration.notify_enabled',
                'required' => false,
            ])
            ->add('notifyEmail', EmailType::class, [
                'label' => 'cyfrowe_nps.form.configuration.notify_email',
                'required' => false,
            ])
            ->add('notifyThreshold', IntegerType::class, [
                'label' => 'cyfrowe_nps.form.configuration.notify_threshold',
                'help' => 'cyfrowe_nps.form.configuration.notify_threshold_help',
            ])
            ->add('notifyWindowMinutes', IntegerType::class, [
                'label' => 'cyfrowe_nps.form.configuration.notify_window_minutes',
                'help' => 'cyfrowe_nps.form.configuration.notify_window_minutes_help',
            ])
            ->add('translations', ResourceTranslationsType::class, [
                'label' => 'cyfrowe_nps.form.configuration.contents',
                'entry_type' => ConfigurationTranslationType::class,
            ])
            ->add('testEmailRecipient', EmailType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'cyfrowe_nps.form.configuration.test_email',
                'help' => 'cyfrowe_nps.form.configuration.test_email_help',
            ])
            ->add('sendTestEmail', SubmitType::class, [
                'label' => 'cyfrowe_nps.form.configuration.send_test_email',
                // Posts the very same form to another route, so the email is built from what is on
                // screen rather than from what was last saved. No script needed.
                'attr' => ['formaction' => $this->urlGenerator->generate('cyfrowe_nps_admin_send_test_email')],
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'cyfrowe_nps_configuration';
    }
}
