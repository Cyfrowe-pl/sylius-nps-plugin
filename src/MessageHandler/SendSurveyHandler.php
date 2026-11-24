<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\MessageHandler;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Mailer\SurveySender;
use Cyfrowe\SyliusNpsPlugin\Message\SendSurvey;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendSurveyHandler
{
    public function __construct(
        private readonly SurveyRepositoryInterface $surveyRepository,
        private readonly SurveySender $sender,
    ) {
    }

    public function __invoke(SendSurvey $message): void
    {
        $survey = $this->surveyRepository->find($message->surveyId);

        if (!$survey instanceof SurveyInterface) {
            // Deleted between dispatch and delivery. Nothing to send and nothing to complain about.
            return;
        }

        $this->sender->send($survey);
    }
}
