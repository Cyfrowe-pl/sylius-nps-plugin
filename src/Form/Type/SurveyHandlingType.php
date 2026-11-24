<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Form\Type;

use Cyfrowe\SyliusNpsPlugin\Entity\HandlingStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SurveyHandlingType extends AbstractType
{
    public function __construct(private readonly string $dataClass)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('handlingStatus', EnumType::class, [
                'class' => HandlingStatus::class,
                'label' => 'cyfrowe_nps.ui.handling_status',
            ])
            ->add('internalNote', TextareaType::class, [
                'label' => 'cyfrowe_nps.ui.internal_note',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => $this->dataClass]);
    }

    public function getBlockPrefix(): string
    {
        return 'cyfrowe_nps_survey_handling';
    }
}
