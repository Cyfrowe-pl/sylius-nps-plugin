<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Mailer;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Shapes what the alert template reads.
 *
 * The language is the channel's own, not the customer's: this email goes to whoever handles
 * complaints at the shop, and a Polish shop reading a survey answered in German still wants the
 * alert in Polish.
 */
final class CriticalAlertData
{
    public const EMAIL_CODE = 'cyfrowe_nps_critical_alert';

    public function __construct(private readonly string $layout)
    {
    }

    /** @return array<string, mixed> */
    public function for(
        SurveyInterface $survey,
        ?ChannelInterface $channel,
        CriticalAlertKind $kind,
        string $fallbackLocaleCode,
    ): array {
        return [
            'survey' => $survey,
            'kind' => $kind->value,
            'subject_key' => $kind->subjectKey(),
            'lead_key' => $kind->leadKey(),
            // Final means the customer submitted the form, so the score cannot move any more.
            'final' => null !== $survey->getCompletedAt(),
            'channel' => $channel,
            'localeCode' => $this->localeOf($channel) ?? $fallbackLocaleCode,
            'layout' => $this->layout,
            'card_route' => ['id' => (int) $survey->getId()],
        ];
    }

    private function localeOf(?ChannelInterface $channel): ?string
    {
        $code = $channel?->getDefaultLocale()?->getCode();

        return is_string($code) && '' !== $code ? $code : null;
    }
}
