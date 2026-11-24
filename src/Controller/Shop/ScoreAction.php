<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Controller\Shop;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Cyfrowe\SyliusNpsPlugin\Security\Automation;
use Cyfrowe\SyliusNpsPlugin\Security\BotGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The number the customer clicked in the email. The score is saved here and the request is then
 * redirected to the form, so that closing the tab straight away still counts as an answer and
 * refreshing the page cannot save it twice.
 */
final class ScoreAction
{
    private const LOWEST = 0;

    private const HIGHEST = 10;

    public function __construct(
        private readonly SurveyRepositoryInterface $surveyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SurveyPages $pages,
        private readonly BotGuard $botGuard,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $token, int $score): Response
    {
        $survey = $this->surveyRepository->findOneByToken($token);

        if (null === $survey) {
            return $this->pages->unknownLink();
        }

        // The record knows the language the survey went out in; the address only carries whatever
        // the shop prefixed its routes with.
        $locale = $survey->getLocaleCode() ?? $request->getLocale();
        $request->setLocale($locale);

        if ($survey->isCompleted()) {
            return $this->pages->alreadyAnswered($survey);
        }

        if ($survey->isExpired($this->clock->now())) {
            return $this->pages->expired($survey);
        }

        $automation = $this->botGuard->inspect($request, $token);

        if (Automation::LinksWorkedThrough === $automation) {
            $this->giveBackWhatWasNeverAnswered($survey);

            return $this->toTheForm($token, $locale);
        }

        if (Automation::RequestIgnored === $automation) {
            $this->noteTheAttempt($survey);

            return $this->toTheForm($token, $locale);
        }

        if ($score >= self::LOWEST && $score <= self::HIGHEST) {
            $this->record($survey, $score);
        }

        return $this->toTheForm($token, $locale);
    }

    /**
     * The links were enumerated, so the first hits left a score nobody chose. It goes, and the
     * record leaves the scored state with it - otherwise it would count towards the index with no
     * score to count.
     */
    private function giveBackWhatWasNeverAnswered(SurveyInterface $survey): void
    {
        $survey->setScore(null);
        $survey->setScoreSetAt(null);
        $survey->setBotSuspected(true);

        if (SurveyStatus::Scored === $survey->getStatus()) {
            $survey->setStatus(SurveyStatus::Sent);
        }

        $this->entityManager->flush();
    }

    /**
     * Something followed the link that was not the customer. Worth knowing about, but this request
     * scored nothing, so there is nothing to take away: whatever they answered earlier stands.
     */
    private function noteTheAttempt(SurveyInterface $survey): void
    {
        $survey->setBotSuspected(true);

        $this->entityManager->flush();
    }

    private function record(SurveyInterface $survey, int $score): void
    {
        $survey->setScore($score);
        $survey->setScoreSetAt($this->clock->now());
        $survey->setStatus(SurveyStatus::Scored);

        $this->entityManager->flush();
    }

    private function toTheForm(string $token, string $locale): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('cyfrowe_nps_shop_survey', [
            'token' => $token,
            '_locale' => $locale,
        ]));
    }
}
