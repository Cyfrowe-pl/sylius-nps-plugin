<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Resource\Model\ResourceInterface;

interface SurveyInterface extends ResourceInterface
{
    public function getId(): ?int;

    public function getToken(): ?string;

    public function setToken(?string $token): void;

    public function getStatus(): SurveyStatus;

    public function setStatus(SurveyStatus $status): void;

    public function getChannelCode(): ?string;

    public function setChannelCode(?string $channelCode): void;

    public function getLocaleCode(): ?string;

    public function setLocaleCode(?string $localeCode): void;

    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    public function getOrderNumber(): ?string;

    public function setOrderNumber(?string $orderNumber): void;

    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): void;

    public function getOrderTotal(): ?int;

    public function setOrderTotal(?int $orderTotal): void;

    public function getCurrencyCode(): ?string;

    public function setCurrencyCode(?string $currencyCode): void;

    public function getShippingMethodCode(): ?string;

    public function setShippingMethodCode(?string $shippingMethodCode): void;

    public function getShippingMethodName(): ?string;

    public function setShippingMethodName(?string $shippingMethodName): void;

    public function getQualifiedAt(): ?\DateTimeInterface;

    public function setQualifiedAt(?\DateTimeInterface $qualifiedAt): void;

    public function getSentAt(): ?\DateTimeInterface;

    public function setSentAt(?\DateTimeInterface $sentAt): void;

    public function getExpiresAt(): ?\DateTimeInterface;

    public function setExpiresAt(?\DateTimeInterface $expiresAt): void;

    public function isExpired(\DateTimeInterface $at): bool;

    public function getScore(): ?int;

    public function setScore(?int $score): void;

    public function getScoreSetAt(): ?\DateTimeInterface;

    public function setScoreSetAt(?\DateTimeInterface $scoreSetAt): void;

    public function getComment(): ?string;

    public function setComment(?string $comment): void;

    public function getCompletedAt(): ?\DateTimeInterface;

    public function setCompletedAt(?\DateTimeInterface $completedAt): void;

    public function isCompleted(): bool;

    public function getSkipReason(): ?SkipReason;

    public function setSkipReason(?SkipReason $skipReason): void;

    public function isBotSuspected(): bool;

    public function setBotSuspected(bool $botSuspected): void;

    public function getHandlingStatus(): HandlingStatus;

    public function setHandlingStatus(HandlingStatus $handlingStatus): void;

    public function getHandledBy(): ?string;

    public function setHandledBy(?string $handledBy): void;

    public function getInternalNote(): ?string;

    public function setInternalNote(?string $internalNote): void;

    public function getCriticalNotifiedAt(): ?\DateTimeInterface;

    public function setCriticalNotifiedAt(?\DateTimeInterface $criticalNotifiedAt): void;

    public function getCriticalCommentNotifiedAt(): ?\DateTimeInterface;

    public function setCriticalCommentNotifiedAt(?\DateTimeInterface $criticalCommentNotifiedAt): void;

    public function getCriticalCorrectionNotifiedAt(): ?\DateTimeInterface;

    public function setCriticalCorrectionNotifiedAt(?\DateTimeInterface $criticalCorrectionNotifiedAt): void;

    /**
     * How many times sending an alert for this record has failed in a row. Zeroed once one gets
     * through, so it counts a run of failures rather than a lifetime total.
     */
    public function getCriticalAttempts(): int;

    public function setCriticalAttempts(int $criticalAttempts): void;
}
