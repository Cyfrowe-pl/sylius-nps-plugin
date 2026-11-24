<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Mailer;

use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sends one alert and records that it went - or that it did not.
 *
 * Whether a record deserves an alert at all is the repository's business, and whether the channel
 * wants them is the command's; this only has to make sure that a send either leaves a mark or
 * leaves a count, and never both.
 */
final class CriticalAlertSender
{
    /**
     * After this many failures in a row the record stops being picked up. The mark stays empty, so
     * the panel still shows it as never alerted about and zeroing the count brings it back.
     */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly RepositoryInterface $channelRepository,
        private readonly SenderInterface $sender,
        private readonly CriticalAlertData $data,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $defaultLocaleCode,
    ) {
    }

    public function send(SurveyInterface $survey, ConfigurationInterface $configuration, CriticalAlertKind $kind): bool
    {
        $recipient = $this->recipientOf($configuration, $survey);

        if (null === $recipient) {
            return false;
        }

        try {
            $this->sender->send(CriticalAlertData::EMAIL_CODE, [$recipient], $this->data->for(
                $survey,
                $this->channelFor($survey),
                $kind,
                $this->defaultLocaleCode,
            ));
        } catch (\Throwable $exception) {
            $this->countTheFailure($survey, $exception);

            return false;
        }

        $this->stamp($survey, $kind);

        return true;
    }

    /**
     * A channel with notifications on but no address is a half-filled settings screen, not a
     * reason to end the run - the remaining channels still have alerts to send.
     */
    private function recipientOf(ConfigurationInterface $configuration, SurveyInterface $survey): ?string
    {
        $recipient = trim((string) $configuration->getNotifyEmail());

        if ('' === $recipient) {
            $this->logger->warning('An NPS alert has nowhere to go: the channel has no notification address.', [
                'channel' => $configuration->getChannelCode(),
                'survey' => $survey->getId(),
            ]);

            return null;
        }

        return $recipient;
    }

    private function countTheFailure(SurveyInterface $survey, \Throwable $exception): void
    {
        $attempts = $survey->getCriticalAttempts() + 1;
        $survey->setCriticalAttempts($attempts);
        $this->entityManager->flush();

        $this->logger->error(
            $attempts >= self::MAX_ATTEMPTS
                ? 'Sending an NPS alert failed for the last time; the record will be left alone from now on.'
                : 'Sending an NPS alert failed; the next run will try again.',
            [
                'survey' => $survey->getId(),
                'attempts' => $attempts,
                'exception' => $exception,
            ],
        );
    }

    private function stamp(SurveyInterface $survey, CriticalAlertKind $kind): void
    {
        $now = $this->clock->now();

        match ($kind) {
            CriticalAlertKind::Primary => $survey->setCriticalNotifiedAt($now),
            CriticalAlertKind::Correction => $survey->setCriticalCorrectionNotifiedAt($now),
            CriticalAlertKind::Comment => $survey->setCriticalCommentNotifiedAt($now),
        };

        // Zeroed on the way out, so a record that failed twice and then got through is not one
        // failure away from being dropped for good.
        $survey->setCriticalAttempts(0);

        $this->entityManager->flush();
    }

    private function channelFor(SurveyInterface $survey): ?ChannelInterface
    {
        $channel = $this->channelRepository->findOneBy(['code' => $survey->getChannelCode()]);

        return $channel instanceof ChannelInterface ? $channel : null;
    }
}
