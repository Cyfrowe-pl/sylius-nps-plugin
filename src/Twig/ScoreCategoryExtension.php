<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Twig;

use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets a template name the band a score falls into without repeating the boundaries. The grid
 * renders the category from the score column, so the alternative would be thresholds in Twig.
 */
final class ScoreCategoryExtension extends AbstractExtension
{
    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nps_score_category', static fn (int $score): string => ScoreCategory::of($score)->value),
        ];
    }
}
