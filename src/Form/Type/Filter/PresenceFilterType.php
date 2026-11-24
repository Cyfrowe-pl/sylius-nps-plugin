<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Form\Type\Filter;

use Cyfrowe\SyliusNpsPlugin\Grid\Filter\PresenceFilter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PresenceFilterType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => [
                'sylius.ui.yes_label' => PresenceFilter::PRESENT,
                'sylius.ui.no_label' => PresenceFilter::ABSENT,
            ],
            'placeholder' => 'sylius.ui.all',
            'data_class' => null,
            'required' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cyfrowe_nps_presence';
    }
}
