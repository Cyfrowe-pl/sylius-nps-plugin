<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Survey;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * The plugin's public entry point. Deciding when an order is ready to be rated belongs to the
 * shop, not here: every shop learns about delivery from a different place.
 */
interface SurveyStarterInterface
{
    /**
     * Returns the survey for the order - the one just created, or the one that already existed.
     * Returns null when the order's channel has the survey switched off.
     *
     * Two things the caller has to know:
     *
     * 1. This flushes the shared entity manager, so whatever the caller has pending is written
     *    out as well.
     * 2. One survey per order is enforced by a unique constraint, and a concurrent call loses
     *    that race with a UniqueConstraintViolationException. The exception is left to surface,
     *    because swallowing it would hand back a closed entity manager and break the caller's
     *    own write somewhere unrelated. Call this from a context that can retry; on the retry
     *    the existing survey is found and returned.
     */
    public function start(OrderInterface $order): ?SurveyInterface;
}
