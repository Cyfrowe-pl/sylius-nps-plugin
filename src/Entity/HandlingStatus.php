<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum HandlingStatus: string implements TranslatableInterface
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('cyfrowe_nps.ui.handling_statuses.' . $this->value, [], null, $locale);
    }
}
