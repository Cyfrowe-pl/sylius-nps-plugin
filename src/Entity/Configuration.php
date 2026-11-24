<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Sylius\Component\Resource\Model\TranslatableTrait;
use Sylius\Component\Resource\Model\TranslationInterface;

class Configuration implements ConfigurationInterface
{
    use TranslatableTrait {
        __construct as private initializeTranslationsCollection;
        getTranslation as private doGetTranslation;
    }

    protected ?int $id = null;

    protected ?string $channelCode = null;

    protected bool $enabled = true;

    protected int $delayDays = 5;

    protected int $cooldownDays = 90;

    protected int $linkValidityDays = 30;

    protected int $sendHourFrom = 10;

    protected int $sendHourTo = 20;

    protected bool $notifyEnabled = true;

    protected ?string $notifyEmail = null;

    protected int $notifyThreshold = 6;

    protected int $notifyWindowMinutes = 30;

    public function __construct()
    {
        $this->initializeTranslationsCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannelCode(): ?string
    {
        return $this->channelCode;
    }

    public function setChannelCode(?string $channelCode): void
    {
        $this->channelCode = $channelCode;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getDelayDays(): int
    {
        return $this->delayDays;
    }

    public function setDelayDays(int $delayDays): void
    {
        $this->delayDays = $delayDays;
    }

    public function getCooldownDays(): int
    {
        return $this->cooldownDays;
    }

    public function setCooldownDays(int $cooldownDays): void
    {
        $this->cooldownDays = $cooldownDays;
    }

    public function getLinkValidityDays(): int
    {
        return $this->linkValidityDays;
    }

    public function setLinkValidityDays(int $linkValidityDays): void
    {
        $this->linkValidityDays = $linkValidityDays;
    }

    public function getSendHourFrom(): int
    {
        return $this->sendHourFrom;
    }

    public function setSendHourFrom(int $sendHourFrom): void
    {
        $this->sendHourFrom = $sendHourFrom;
    }

    public function getSendHourTo(): int
    {
        return $this->sendHourTo;
    }

    public function setSendHourTo(int $sendHourTo): void
    {
        $this->sendHourTo = $sendHourTo;
    }

    public function isNotifyEnabled(): bool
    {
        return $this->notifyEnabled;
    }

    public function setNotifyEnabled(bool $notifyEnabled): void
    {
        $this->notifyEnabled = $notifyEnabled;
    }

    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }

    public function setNotifyEmail(?string $notifyEmail): void
    {
        $this->notifyEmail = $notifyEmail;
    }

    public function getNotifyThreshold(): int
    {
        return $this->notifyThreshold;
    }

    public function setNotifyThreshold(int $notifyThreshold): void
    {
        $this->notifyThreshold = $notifyThreshold;
    }

    public function getNotifyWindowMinutes(): int
    {
        return $this->notifyWindowMinutes;
    }

    public function setNotifyWindowMinutes(int $notifyWindowMinutes): void
    {
        $this->notifyWindowMinutes = $notifyWindowMinutes;
    }

    /** @return ConfigurationTranslationInterface */
    public function getTranslation(?string $locale = null): TranslationInterface
    {
        $translation = $this->doGetTranslation($locale);
        assert($translation instanceof ConfigurationTranslationInterface);

        return $translation;
    }

    protected function createTranslation(): TranslationInterface
    {
        return new ConfigurationTranslation();
    }
}
