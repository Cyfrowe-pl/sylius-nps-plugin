<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Controller\Shop;

use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Survey\SurveyWording;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The screens a customer can land on. None of them is an error page: a link that expired or was
 * already used is a normal thing to happen weeks after an email went out, and the customer should
 * read a sentence, not a status code.
 */
final class SurveyPages
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SurveyWording $wording,
        private readonly string $layout,
    ) {
    }

    public function unknownLink(): Response
    {
        return $this->render('unknown.html.twig');
    }

    public function expired(SurveyInterface $survey): Response
    {
        return $this->render('expired.html.twig', ['survey' => $survey]);
    }

    public function alreadyAnswered(SurveyInterface $survey): Response
    {
        return $this->render('completed.html.twig', [
            'survey' => $survey,
            'contents' => $this->wording->forSurvey($survey),
        ]);
    }

    public function form(SurveyInterface $survey, FormInterface $form): Response
    {
        return $this->render('form.html.twig', [
            'survey' => $survey,
            'form' => $form->createView(),
            'contents' => $this->wording->forSurvey($survey),
            'promoter_from' => ScoreCategory::Promoter->lowestScore(),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): Response
    {
        $context['layout'] = $this->layout;

        return new Response($this->twig->render('@CyfroweSyliusNpsPlugin/shop/survey/' . $template, $context));
    }
}
