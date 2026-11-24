<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Mailer;

use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

/**
 * Sends the survey email to whoever is editing the settings, built from the values in front of them
 * rather than from what is stored. Without that, checking a change to the wording means placing a
 * real order or editing the database.
 *
 * Synchronous on purpose: the person clicking wants to know now whether it went out.
 */
final class TestEmailSender
{
    private const SAMPLE_ORDER_NUMBER = '000000';

    public function __construct(
        private readonly RepositoryInterface $channelRepository,
        private readonly SenderInterface $sender,
        private readonly SurveyEmailData $data,
    ) {
    }

    public function send(ConfigurationInterface $configuration, string $recipient, string $localeCode): void
    {
        $survey = new Survey();

        // Never persisted, and the token is random rather than borrowed from a real survey: the
        // links in a test email must not be able to score somebody else's.
        $survey->setToken(bin2hex(random_bytes(16)));
        $survey->setOrderNumber(self::SAMPLE_ORDER_NUMBER);
        $survey->setChannelCode($configuration->getChannelCode());
        $survey->setLocaleCode($localeCode);

        $this->sender->send(
            SurveySender::EMAIL_CODE,
            [$recipient],
            $this->data->for($survey, $configuration, $this->channel($configuration), null),
        );
    }

    private function channel(ConfigurationInterface $configuration): ?ChannelInterface
    {
        $channel = $this->channelRepository->findOneBy(['code' => $configuration->getChannelCode()]);

        return $channel instanceof ChannelInterface ? $channel : null;
    }
}
