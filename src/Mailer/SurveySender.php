<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Mailer;

use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SkipReason;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Repository\SurveyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Everything that decides whether a survey goes out lives here rather than in the command, so a
 * message sitting in a queue for an hour is judged against the state at the moment it is handled.
 */
final class SurveySender
{
    public const EMAIL_CODE = 'cyfrowe_nps_survey';

    public function __construct(
        private readonly SurveyRepositoryInterface $surveyRepository,
        private readonly RepositoryInterface $configurationRepository,
        private readonly RepositoryInterface $channelRepository,
        private readonly SenderInterface $sender,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly SurveyEmailData $data,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(SurveyInterface $survey): void
    {
        if (SurveyStatus::Pending !== $survey->getStatus() && SurveyStatus::Queued !== $survey->getStatus()) {
            // Already sent, already answered, already skipped - a repeated message is a no-op.
            return;
        }

        $skip = $this->reasonToSkip($survey);

        if (null !== $skip) {
            $this->skip($survey, $skip);

            return;
        }

        $order = $survey->getOrder();
        $configuration = $this->configurationFor($survey);
        assert($order instanceof OrderInterface);
        assert($configuration instanceof ConfigurationInterface);

        // A failure here is left to Messenger: nothing has gone out yet, and the survey is still
        // QUEUED, which this method accepts, so a retried message simply tries again.
        $this->sender->send(self::EMAIL_CODE, [(string) $order->getCustomer()?->getEmail()], $this->data->for(
            $survey,
            $configuration,
            $this->channelFor($survey),
            $order->getCustomer()?->getFirstName(),
        ));

        $sentAt = $this->clock->now();
        $survey->setSentAt($sentAt);
        $survey->setExpiresAt($sentAt->modify(sprintf('+%d days', $configuration->getLinkValidityDays())));
        $survey->setStatus(SurveyStatus::Sent);

        try {
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            // The email is out but the record still says QUEUED, so a retry would send it again.
            $this->logger->critical('An NPS survey was sent but its state could not be saved; do not retry it.', [
                'survey' => $survey->getId(),
                'exception' => $exception,
            ]);

            throw new UnrecoverableMessageHandlingException(sprintf(
                'NPS survey %d was sent but its state could not be saved. Retrying would send it again.',
                (int) $survey->getId(),
            ), 0, $exception);
        }
    }

    private function reasonToSkip(SurveyInterface $survey): ?SkipReason
    {
        $order = $survey->getOrder();

        if (!$order instanceof OrderInterface) {
            return SkipReason::OrderMissing;
        }

        if (OrderInterface::STATE_CANCELLED === $order->getState()) {
            return SkipReason::OrderCancelled;
        }

        $customer = $order->getCustomer();

        if (!$customer instanceof CustomerInterface || null === $customer->getEmail()) {
            return SkipReason::NoRecipient;
        }

        $configuration = $this->configurationFor($survey);

        if (!$configuration instanceof ConfigurationInterface || !$configuration->isEnabled()) {
            return SkipReason::ChannelDisabled;
        }

        // The record holds the channel as plain text, so nothing stops a shop removing the channel
        // it names. The email cannot be built without one - the Sylius layout it is wrapped in asks
        // the channel context and throws - so this is a reason not to send rather than a crash.
        if (!$this->channelFor($survey) instanceof ChannelInterface) {
            return SkipReason::ChannelMissing;
        }

        $cooldownDays = $configuration->getCooldownDays();

        if ($cooldownDays > 0 && $this->surveyRepository->hasBeenSentToCustomerSince(
            $customer,
            $this->clock->now()->modify(sprintf('-%d days', $cooldownDays)),
        )) {
            return SkipReason::Cooldown;
        }

        return null;
    }

    private function skip(SurveyInterface $survey, SkipReason $reason): void
    {
        $survey->setStatus(SurveyStatus::Skipped);
        $survey->setSkipReason($reason);

        $this->entityManager->flush();
    }

    private function configurationFor(SurveyInterface $survey): ?object
    {
        return $this->configurationRepository->findOneBy(['channelCode' => $survey->getChannelCode()]);
    }

    private function channelFor(SurveyInterface $survey): ?ChannelInterface
    {
        $channel = $this->channelRepository->findOneBy(['code' => $survey->getChannelCode()]);

        return $channel instanceof ChannelInterface ? $channel : null;
    }
}
