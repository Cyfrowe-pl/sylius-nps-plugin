<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Mailer;

/**
 * Which of the three things the alert is saying. One email with three shapes rather than three
 * emails: everything below the opening sentence - the order, the value, the link - is the same,
 * and an operator reading the second message wants the same context as the first.
 */
enum CriticalAlertKind: string
{
    /** The score is at or below the threshold and nobody has been told yet. */
    case Primary = 'primary';

    /** The customer has since put the score above the threshold. Saves a call to a happy customer. */
    case Correction = 'correction';

    /** The alert went out without a comment and the customer has since written one. */
    case Comment = 'comment';

    /**
     * The wording lives in translations and the mapping to it lives here, because Sylius renders
     * an email's subject and body as two separate blocks - and a template-level {% set %} does not
     * run for either of them.
     */
    public function subjectKey(): string
    {
        return $this->keyFor('subject');
    }

    public function leadKey(): string
    {
        return $this->keyFor('lead');
    }

    private function keyFor(string $part): string
    {
        $key = 'cyfrowe_nps.email.critical.' . $part;

        return self::Primary === $this ? $key : $key . '_' . $this->value;
    }
}
