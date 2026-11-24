<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

final class SurveyAnswerType extends AbstractType
{
    public const COMMENT_LIMIT = 2000;

    public function __construct(private readonly string $dataClass)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('score', ChoiceType::class, [
                'choices' => array_combine(range(0, 10), range(0, 10)),
                'expanded' => true,
                'multiple' => false,
                'label' => 'cyfrowe_nps.shop.survey.score',
            ])
            ->add('comment', TextareaType::class, [
                'required' => false,
                'label' => 'cyfrowe_nps.shop.survey.comment',
                'help' => 'cyfrowe_nps.shop.survey.comment_help',
                // Anyone holding a token can post here as often as they like, and the column is a
                // long text. A limit is the only thing standing between that and the database.
                'constraints' => [new Length(max: self::COMMENT_LIMIT, maxMessage: 'cyfrowe_nps.survey.comment.too_long')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => $this->dataClass]);
    }

    public function getBlockPrefix(): string
    {
        return 'cyfrowe_nps_survey_answer';
    }
}
