<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum SurveyStatus: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Scored = 'scored';
    case Completed = 'completed';
    case Skipped = 'skipped';

    /**
     * Only these states carry a score that counts towards the index.
     *
     * @return list<self>
     */
    public static function countedTowardsIndex(): array
    {
        return [self::Scored, self::Completed];
    }

    public function countsTowardsIndex(): bool
    {
        return \in_array($this, self::countedTowardsIndex(), true);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('cyfrowe_nps.ui.survey_statuses.' . $this->value, [], null, $locale);
    }
}
