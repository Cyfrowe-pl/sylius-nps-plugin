<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Survey;

use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationTranslationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

/**
 * The wording a given survey is asked in: the channel's settings, in the language the survey was
 * created for. A shop that never filled them in gets null, and the templates fall back to the
 * package's own translations rather than showing an empty page.
 */
final class SurveyWording
{
    public function __construct(private readonly RepositoryInterface $configurationRepository)
    {
    }

    public function forSurvey(SurveyInterface $survey): ?ConfigurationTranslationInterface
    {
        $configuration = $this->configurationRepository->findOneBy(['channelCode' => $survey->getChannelCode()]);

        if (!$configuration instanceof ConfigurationInterface) {
            return null;
        }

        return $configuration->getTranslation($survey->getLocaleCode());
    }
}
