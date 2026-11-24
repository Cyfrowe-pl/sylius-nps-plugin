<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Form\Type\Filter;

use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ScoreCategoryFilterType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => $this->choices(),
            'multiple' => true,
            'expanded' => false,
            'required' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cyfrowe_nps_score_category_filter';
    }

    /** @return array<string, string> */
    private function choices(): array
    {
        $choices = [];

        foreach (ScoreCategory::cases() as $category) {
            $choices['cyfrowe_nps.ui.categories.' . $category->value] = $category->value;
        }

        return $choices;
    }
}
