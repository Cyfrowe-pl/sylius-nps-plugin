<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Form\Type;

use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class ConfigurationTranslationType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emailSubject', TextType::class, [
                'label' => 'cyfrowe_nps.form.configuration.email_subject',
                'required' => false,
            ])
            ->add('mainQuestion', TextareaType::class, [
                'label' => 'cyfrowe_nps.form.configuration.main_question',
                'help' => 'cyfrowe_nps.form.configuration.main_question_help',
                'required' => false,
            ])
            ->add('questionLow', TextareaType::class, [
                'label' => 'cyfrowe_nps.form.configuration.question_low',
                'help' => 'cyfrowe_nps.form.configuration.question_low_help',
                'required' => false,
            ])
            ->add('questionHigh', TextareaType::class, [
                'label' => 'cyfrowe_nps.form.configuration.question_high',
                'help' => 'cyfrowe_nps.form.configuration.question_high_help',
                'required' => false,
            ])
            ->add('thankYouText', TextareaType::class, [
                'label' => 'cyfrowe_nps.form.configuration.thank_you_text',
                'required' => false,
            ])
            ->add('footerNote', TextareaType::class, [
                'label' => 'cyfrowe_nps.form.configuration.footer_note',
                'required' => false,
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'cyfrowe_nps_configuration_translation';
    }
}
