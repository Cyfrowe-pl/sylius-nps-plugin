<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Message;

/**
 * Carries the identifier and nothing else, so the handler always works on the record as it is when
 * the message is picked up rather than as it was when it was dispatched.
 */
final class SendSurvey
{
    public function __construct(public readonly int $surveyId)
    {
    }
}
