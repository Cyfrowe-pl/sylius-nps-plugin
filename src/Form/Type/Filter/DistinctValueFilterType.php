<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Form\Type\Filter;

use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A dropdown built from the values actually present in the records, not from the shop's catalogue.
 *
 * That difference matters for history: a shipping method retired last year still has surveys
 * attached to it, and those have to stay filterable. The applying side is Sylius' own select
 * filter - only the choices are ours.
 */
final class DistinctValueFilterType extends AbstractType
{
    public function __construct(private readonly SurveyRepositoryInterface $surveyRepository)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('distinct_field')
            ->setAllowedTypes('distinct_field', 'string')
            ->setDefaults([
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'choices' => function (Options $options): array {
                    /** @var string $field */
                    $field = $options['distinct_field'];

                    return $this->surveyRepository->findDistinctValues($field);
                },
            ])
        ;
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cyfrowe_nps_distinct_value_filter';
    }
}
