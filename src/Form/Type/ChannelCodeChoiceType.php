<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Form\Type;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Picks a channel but stores its code, so the configuration survives the channel being
 * renamed and never holds a reference to a shop entity.
 */
final class ChannelCodeChoiceType extends AbstractType
{
    public function __construct(private readonly RepositoryInterface $channelRepository)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => function (Options $options): array {
                $choices = [];

                foreach ($this->channelRepository->findAll() as $channel) {
                    assert($channel instanceof ChannelInterface);

                    $code = $channel->getCode();
                    if (null === $code) {
                        continue;
                    }

                    $choices[$channel->getName() ?? $code] = $code;
                }

                return $choices;
            },
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'cyfrowe_nps_channel_code_choice';
    }
}
