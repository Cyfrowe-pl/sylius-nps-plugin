<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Controller\Shop;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Form\Type\SurveyAnswerType;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The survey itself. The score shown here is read from the record, never from the address, so the
 * customer cannot set one by editing the URL and the page still shows their answer weeks later.
 */
final class SurveyPageAction
{
    public function __construct(
        private readonly SurveyRepositoryInterface $surveyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
        private readonly SurveyPages $pages,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        $survey = $this->surveyRepository->findOneByToken($token);

        if (null === $survey) {
            return $this->pages->unknownLink();
        }

        $request->setLocale($survey->getLocaleCode() ?? $request->getLocale());

        if ($survey->isCompleted()) {
            return $this->pages->alreadyAnswered($survey);
        }

        if ($survey->isExpired($this->clock->now())) {
            return $this->pages->expired($survey);
        }

        $form = $this->formFactory->create(SurveyAnswerType::class, $survey);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->complete($survey);

            return $this->pages->alreadyAnswered($survey);
        }

        if ($form->isSubmitted()) {
            // The form writes straight onto the record, so a rejected submission - a missing CSRF
            // token, an overlong comment - has already changed it in memory. Anything flushing
            // later in the request would make that stick, so the record is read back as it was.
            $this->entityManager->refresh($survey);
        }

        return $this->pages->form($survey, $form);
    }

    private function complete(SurveyInterface $survey): void
    {
        $now = $this->clock->now();

        if (null === $survey->getScoreSetAt()) {
            $survey->setScoreSetAt($now);
        }

        $survey->setCompletedAt($now);
        $survey->setStatus(SurveyStatus::Completed);

        $this->entityManager->flush();
    }
}
