<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Controller\Admin;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Form\Type\SurveyHandlingType;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Records who is dealing with an answer and what they made of it. Without this the team cannot tell
 * a detractor somebody called back from one everybody forgot about.
 */
#[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
final class SaveHandlingAction
{
    public function __construct(
        private readonly SurveyRepositoryInterface $repository,
        private readonly FormFactoryInterface $formFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    public function __invoke(Request $request, int $id): Response
    {
        $survey = $this->repository->find($id);

        if (!$survey instanceof SurveyInterface) {
            throw new NotFoundHttpException(sprintf('There is no answer with the id %d.', $id));
        }

        $form = $this->formFactory->create(SurveyHandlingType::class, $survey);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // The form writes onto the managed record before it is validated, so a rejected
            // submission has already changed the one in memory. Left alone, the next flush of the
            // request would persist a change nobody accepted - a forged request among them.
            $this->entityManager->refresh($survey);

            return $this->backToTheAnswer($request, $id, 'error', 'cyfrowe_nps.flash.handling.failed');
        }

        $survey->setHandledBy($this->whoIsSignedIn());

        $this->entityManager->flush();

        return $this->backToTheAnswer($request, $id, 'success', 'cyfrowe_nps.flash.handling.saved');
    }

    /**
     * Taken from the session rather than typed in: the point is who actually dealt with it.
     */
    private function whoIsSignedIn(): ?string
    {
        return $this->security->getUser()?->getUserIdentifier();
    }

    private function backToTheAnswer(Request $request, int $id, string $type, string $message): RedirectResponse
    {
        $session = $request->getSession();

        if (method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }

        return new RedirectResponse($this->urlGenerator->generate('cyfrowe_nps_admin_survey_show', ['id' => $id]));
    }
}
