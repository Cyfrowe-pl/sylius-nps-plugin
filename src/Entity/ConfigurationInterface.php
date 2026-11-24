<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Sylius\Component\Resource\Model\ResourceInterface;
use Sylius\Component\Resource\Model\TranslatableInterface;
use Sylius\Component\Resource\Model\TranslationInterface;

interface ConfigurationInterface extends ResourceInterface, TranslatableInterface
{
    /** @return ConfigurationTranslationInterface */
    public function getTranslation(?string $locale = null): TranslationInterface;

    public function getId(): ?int;

    public function getChannelCode(): ?string;

    public function setChannelCode(?string $channelCode): void;

    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): void;

    public function getDelayDays(): int;

    public function setDelayDays(int $delayDays): void;

    public function getCooldownDays(): int;

    public function setCooldownDays(int $cooldownDays): void;

    public function getLinkValidityDays(): int;

    public function setLinkValidityDays(int $linkValidityDays): void;

    public function getSendHourFrom(): int;

    public function setSendHourFrom(int $sendHourFrom): void;

    public function getSendHourTo(): int;

    public function setSendHourTo(int $sendHourTo): void;

    public function isNotifyEnabled(): bool;

    public function setNotifyEnabled(bool $notifyEnabled): void;

    public function getNotifyEmail(): ?string;

    public function setNotifyEmail(?string $notifyEmail): void;

    public function getNotifyThreshold(): int;

    public function setNotifyThreshold(int $notifyThreshold): void;

    public function getNotifyWindowMinutes(): int;

    public function setNotifyWindowMinutes(int $notifyWindowMinutes): void;
}
