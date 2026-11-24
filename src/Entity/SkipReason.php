<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why a survey was never sent. Stored as a code rather than a sentence, so the admin screen can
 * translate it and a shop can filter on it.
 */
enum SkipReason: string implements TranslatableInterface
{
    case NoRecipient = 'no_recipient';
    case Cooldown = 'cooldown';
    case ChannelDisabled = 'channel_disabled';
    case ChannelMissing = 'channel_missing';
    case OrderCancelled = 'order_cancelled';
    case OrderMissing = 'order_missing';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('cyfrowe_nps.ui.skip_reasons.' . $this->value, [], null, $locale);
    }
}
