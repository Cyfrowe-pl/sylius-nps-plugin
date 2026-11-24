<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Nps;

/**
 * The index itself: the share of promoters less the share of detractors, as a whole number between
 * -100 and 100. Passives count towards the total and towards nothing else, which is the whole point
 * of the measure.
 */
final class NpsCalculator
{
    /**
     * Null rather than zero when nothing has been answered. Zero is a real result - as many
     * promoters as detractors - and a filter that matched no records must not read like one.
     */
    public function index(int $promoters, int $detractors, int $answers): ?int
    {
        if ($answers < 1) {
            return null;
        }

        return (int) round(100 * ($promoters - $detractors) / $answers);
    }

    public function share(int $count, int $answers): float
    {
        if ($answers < 1) {
            return 0.0;
        }

        return round(100 * $count / $answers, 1);
    }

    /**
     * Null rather than zero when nothing has been sent, for the same reason as the index: an empty
     * set has no rate, and a zero would read as a campaign nobody answered.
     */
    public function rate(int $count, int $sent): ?float
    {
        if ($sent < 1) {
            return null;
        }

        return round(100 * $count / $sent, 1);
    }
}
