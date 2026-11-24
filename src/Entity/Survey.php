<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;

class Survey implements SurveyInterface
{
    protected ?int $id = null;

    protected ?string $token = null;

    protected SurveyStatus $status = SurveyStatus::Pending;

    protected ?string $channelCode = null;

    protected ?string $localeCode = null;

    protected ?OrderInterface $order = null;

    protected ?string $orderNumber = null;

    protected ?CustomerInterface $customer = null;

    protected ?int $orderTotal = null;

    protected ?string $currencyCode = null;

    protected ?string $shippingMethodCode = null;

    protected ?string $shippingMethodName = null;

    protected ?\DateTimeInterface $qualifiedAt = null;

    protected ?\DateTimeInterface $sentAt = null;

    protected ?\DateTimeInterface $expiresAt = null;

    protected ?int $score = null;

    protected ?\DateTimeInterface $scoreSetAt = null;

    protected ?string $comment = null;

    protected ?\DateTimeInterface $completedAt = null;

    protected ?SkipReason $skipReason = null;

    protected bool $botSuspected = false;

    protected HandlingStatus $handlingStatus = HandlingStatus::New;

    protected ?string $handledBy = null;

    protected ?string $internalNote = null;

    protected ?\DateTimeInterface $criticalNotifiedAt = null;

    protected ?\DateTimeInterface $criticalCommentNotifiedAt = null;

    protected ?\DateTimeInterface $criticalCorrectionNotifiedAt = null;

    protected int $criticalAttempts = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    public function getStatus(): SurveyStatus
    {
        return $this->status;
    }

    public function setStatus(SurveyStatus $status): void
    {
        $this->status = $status;
    }

    public function getChannelCode(): ?string
    {
        return $this->channelCode;
    }

    public function setChannelCode(?string $channelCode): void
    {
        $this->channelCode = $channelCode;
    }

    public function getLocaleCode(): ?string
    {
        return $this->localeCode;
    }

    public function setLocaleCode(?string $localeCode): void
    {
        $this->localeCode = $localeCode;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(?string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }

    public function getOrderTotal(): ?int
    {
        return $this->orderTotal;
    }

    public function setOrderTotal(?int $orderTotal): void
    {
        $this->orderTotal = $orderTotal;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getShippingMethodCode(): ?string
    {
        return $this->shippingMethodCode;
    }

    public function setShippingMethodCode(?string $shippingMethodCode): void
    {
        $this->shippingMethodCode = $shippingMethodCode;
    }

    public function getShippingMethodName(): ?string
    {
        return $this->shippingMethodName;
    }

    public function setShippingMethodName(?string $shippingMethodName): void
    {
        $this->shippingMethodName = $shippingMethodName;
    }

    public function getQualifiedAt(): ?\DateTimeInterface
    {
        return $this->qualifiedAt;
    }

    public function setQualifiedAt(?\DateTimeInterface $qualifiedAt): void
    {
        $this->qualifiedAt = $qualifiedAt;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $sentAt): void
    {
        $this->sentAt = $sentAt;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function isExpired(\DateTimeInterface $at): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < $at;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): void
    {
        $this->score = $score;
    }

    public function getScoreSetAt(): ?\DateTimeInterface
    {
        return $this->scoreSetAt;
    }

    public function setScoreSetAt(?\DateTimeInterface $scoreSetAt): void
    {
        $this->scoreSetAt = $scoreSetAt;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): void
    {
        $this->completedAt = $completedAt;
    }

    public function isCompleted(): bool
    {
        return null !== $this->completedAt;
    }

    public function getSkipReason(): ?SkipReason
    {
        return $this->skipReason;
    }

    public function setSkipReason(?SkipReason $skipReason): void
    {
        $this->skipReason = $skipReason;
    }

    public function isBotSuspected(): bool
    {
        return $this->botSuspected;
    }

    public function setBotSuspected(bool $botSuspected): void
    {
        $this->botSuspected = $botSuspected;
    }

    public function getHandlingStatus(): HandlingStatus
    {
        return $this->handlingStatus;
    }

    public function setHandlingStatus(HandlingStatus $handlingStatus): void
    {
        $this->handlingStatus = $handlingStatus;
    }

    public function getHandledBy(): ?string
    {
        return $this->handledBy;
    }

    public function setHandledBy(?string $handledBy): void
    {
        $this->handledBy = $handledBy;
    }

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function setInternalNote(?string $internalNote): void
    {
        $this->internalNote = $internalNote;
    }

    public function getCriticalNotifiedAt(): ?\DateTimeInterface
    {
        return $this->criticalNotifiedAt;
    }

    public function setCriticalNotifiedAt(?\DateTimeInterface $criticalNotifiedAt): void
    {
        $this->criticalNotifiedAt = $criticalNotifiedAt;
    }

    public function getCriticalCommentNotifiedAt(): ?\DateTimeInterface
    {
        return $this->criticalCommentNotifiedAt;
    }

    public function setCriticalCommentNotifiedAt(?\DateTimeInterface $criticalCommentNotifiedAt): void
    {
        $this->criticalCommentNotifiedAt = $criticalCommentNotifiedAt;
    }

    public function getCriticalCorrectionNotifiedAt(): ?\DateTimeInterface
    {
        return $this->criticalCorrectionNotifiedAt;
    }

    public function setCriticalCorrectionNotifiedAt(?\DateTimeInterface $criticalCorrectionNotifiedAt): void
    {
        $this->criticalCorrectionNotifiedAt = $criticalCorrectionNotifiedAt;
    }

    public function getCriticalAttempts(): int
    {
        return $this->criticalAttempts;
    }

    public function setCriticalAttempts(int $criticalAttempts): void
    {
        $this->criticalAttempts = $criticalAttempts;
    }
}
