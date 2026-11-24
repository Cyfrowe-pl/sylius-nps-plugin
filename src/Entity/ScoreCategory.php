<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

/**
 * The three bands a score falls into. The boundaries live here and nowhere else - the index, the
 * category filter in the admin list and the follow-up question in the email and on the survey page
 * all read them from here, so moving a threshold moves all of them at once.
 */
enum ScoreCategory: string
{
    case Detractor = 'detractor';
    case Passive = 'passive';
    case Promoter = 'promoter';

    public static function of(int $score): self
    {
        foreach (self::cases() as $category) {
            [$from, $to] = $category->scoreRange();

            if ($score >= $from && $score <= $to) {
                return $category;
            }
        }

        throw new \InvalidArgumentException(sprintf('A score of %d is outside the 0-10 scale.', $score));
    }

    /** @return array{int, int} */
    public function scoreRange(): array
    {
        return match ($this) {
            self::Detractor => [0, 6],
            self::Passive => [7, 8],
            self::Promoter => [9, 10],
        };
    }

    public function lowestScore(): int
    {
        return $this->scoreRange()[0];
    }
}
