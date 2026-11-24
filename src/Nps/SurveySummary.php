<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Nps;

use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;

/**
 * What the figures above the admin list say, for one set of filters.
 */
final class SurveySummary
{
    /**
     * @param array<string, int> $countByCategory keyed by ScoreCategory value
     * @param array<string, float> $shareByCategory keyed by ScoreCategory value
     * @param array<int, int> $scoreDistribution every score from 0 to 10, including the unused ones
     */
    public function __construct(
        public readonly ?int $index,
        public readonly int $answers,
        public readonly ?float $averageScore,
        public readonly array $countByCategory,
        public readonly array $shareByCategory,
        public readonly array $scoreDistribution,
        public readonly int $withComment,
        public readonly float $commentShare,
        public readonly int $sent,
        public readonly int $clicked,
        public readonly int $completed,
        public readonly ?float $responseRate,
        public readonly ?float $completionRate,
        public readonly bool $ratesWithheld,
    ) {
    }

    public function countOf(ScoreCategory $category): int
    {
        return $this->countByCategory[$category->value] ?? 0;
    }

    public function shareOf(ScoreCategory $category): float
    {
        return $this->shareByCategory[$category->value] ?? 0.0;
    }
}
