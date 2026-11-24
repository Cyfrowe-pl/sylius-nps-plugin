<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Twig;

use Cyfrowe\SyliusNpsPlugin\Nps\SurveySummary;
use Cyfrowe\SyliusNpsPlugin\Nps\SurveySummaryProvider;
use Sylius\Component\Grid\View\GridViewInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SurveySummaryExtension extends AbstractExtension
{
    public function __construct(private readonly SurveySummaryProvider $summaryProvider)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nps_survey_summary', $this->summarise(...)),
        ];
    }

    public function summarise(GridViewInterface $gridView): SurveySummary
    {
        return $this->summaryProvider->forGridView($gridView);
    }
}
