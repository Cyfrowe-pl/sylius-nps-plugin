<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Mailer;

use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Shapes what the email template reads. Shared, because the test button on the settings screen has
 * to render exactly the same email as the real send - otherwise previewing a change proves nothing.
 */
final class SurveyEmailData
{
    private const LOWEST = 0;

    private const HIGHEST = 10;

    public function __construct(private readonly string $layout)
    {
    }

    /** @return array<string, mixed> */
    public function for(
        SurveyInterface $survey,
        ConfigurationInterface $configuration,
        ?ChannelInterface $channel,
        ?string $customerFirstName,
    ): array {
        $localeCode = (string) $survey->getLocaleCode();

        return [
            'survey' => $survey,
            'customer_first_name' => $customerFirstName,
            'contents' => $configuration->getTranslation($localeCode),
            'channel' => $channel,
            'localeCode' => $localeCode,
            'layout' => $this->layout,
            'score_routes' => $this->scoreRoutes($survey, $localeCode),
        ];
    }

    /**
     * Only the route parameters are built here. The template turns them into absolute addresses
     * with the channel's own hostname, which is the one thing a worker process cannot guess.
     *
     * @return array<int, array{token: string, score: int, _locale: string}>
     */
    private function scoreRoutes(SurveyInterface $survey, string $localeCode): array
    {
        $parameters = [];

        foreach (range(self::LOWEST, self::HIGHEST) as $score) {
            $parameters[$score] = [
                'token' => (string) $survey->getToken(),
                'score' => $score,
                '_locale' => $localeCode,
            ];
        }

        return $parameters;
    }
}
