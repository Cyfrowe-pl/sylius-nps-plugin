<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Controller\Admin;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Form\Type\SurveyHandlingType;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Cyfrowe\SyliusNpsPlugin\Survey\SurveyWording;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * One answer in full. The list truncates the comment, and a critical score is exactly the case
 * where the rest of the sentence is the point.
 */
#[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
final class ShowResponseAction
{
    public function __construct(
        private readonly SurveyRepositoryInterface $repository,
        private readonly FormFactoryInterface $formFactory,
        private readonly SurveyWording $wording,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(int $id): Response
    {
        $survey = $this->repository->find($id);

        if (!$survey instanceof SurveyInterface) {
            throw new NotFoundHttpException(sprintf('There is no answer with the id %d.', $id));
        }

        return new Response($this->twig->render('@CyfroweSyliusNpsPlugin/admin/survey/show.html.twig', [
            'survey' => $survey,
            'contents' => $this->wording->forSurvey($survey),
            'form' => $this->formFactory->create(SurveyHandlingType::class, $survey)->createView(),
        ]));
    }
}
