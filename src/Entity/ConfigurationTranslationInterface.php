<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Entity;

use Sylius\Component\Resource\Model\ResourceInterface;
use Sylius\Component\Resource\Model\TranslationInterface;

interface ConfigurationTranslationInterface extends ResourceInterface, TranslationInterface
{
    public function getId(): ?int;

    public function getEmailSubject(): ?string;

    public function setEmailSubject(?string $emailSubject): void;

    public function getMainQuestion(): ?string;

    public function setMainQuestion(?string $mainQuestion): void;

    public function getQuestionLow(): ?string;

    public function setQuestionLow(?string $questionLow): void;

    public function getQuestionHigh(): ?string;

    public function setQuestionHigh(?string $questionHigh): void;

    public function getThankYouText(): ?string;

    public function setThankYouText(?string $thankYouText): void;

    public function getFooterNote(): ?string;

    public function setFooterNote(?string $footerNote): void;
}
