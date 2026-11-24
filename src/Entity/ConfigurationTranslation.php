<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Sylius\Component\Resource\Model\AbstractTranslation;

class ConfigurationTranslation extends AbstractTranslation implements ConfigurationTranslationInterface
{
    protected ?int $id = null;

    protected ?string $emailSubject = null;

    protected ?string $mainQuestion = null;

    protected ?string $questionLow = null;

    protected ?string $questionHigh = null;

    protected ?string $thankYouText = null;

    protected ?string $footerNote = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmailSubject(): ?string
    {
        return $this->emailSubject;
    }

    public function setEmailSubject(?string $emailSubject): void
    {
        $this->emailSubject = $emailSubject;
    }

    public function getMainQuestion(): ?string
    {
        return $this->mainQuestion;
    }

    public function setMainQuestion(?string $mainQuestion): void
    {
        $this->mainQuestion = $mainQuestion;
    }

    public function getQuestionLow(): ?string
    {
        return $this->questionLow;
    }

    public function setQuestionLow(?string $questionLow): void
    {
        $this->questionLow = $questionLow;
    }

    public function getQuestionHigh(): ?string
    {
        return $this->questionHigh;
    }

    public function setQuestionHigh(?string $questionHigh): void
    {
        $this->questionHigh = $questionHigh;
    }

    public function getThankYouText(): ?string
    {
        return $this->thankYouText;
    }

    public function setThankYouText(?string $thankYouText): void
    {
        $this->thankYouText = $thankYouText;
    }

    public function getFooterNote(): ?string
    {
        return $this->footerNote;
    }

    public function setFooterNote(?string $footerNote): void
    {
        $this->footerNote = $footerNote;
    }
}
