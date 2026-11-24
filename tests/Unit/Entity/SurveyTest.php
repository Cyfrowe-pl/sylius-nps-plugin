<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\Entity;

use Cyfrowe\SyliusNpsPlugin\Entity\HandlingStatus;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use PHPUnit\Framework\TestCase;

final class SurveyTest extends TestCase
{
    public function testStartsPendingAndUnhandled(): void
    {
        $survey = new Survey();

        self::assertSame(SurveyStatus::Pending, $survey->getStatus());
        self::assertSame(HandlingStatus::New, $survey->getHandlingStatus());
        self::assertFalse($survey->isBotSuspected());
        self::assertFalse($survey->isCompleted());
    }

    public function testIsExpiredOnlyAfterTheExpiryMoment(): void
    {
        $survey = new Survey();
        $survey->setExpiresAt(new \DateTimeImmutable('2026-09-30 12:00:00'));

        self::assertFalse($survey->isExpired(new \DateTimeImmutable('2026-09-30 11:59:59')));
        self::assertTrue($survey->isExpired(new \DateTimeImmutable('2026-09-30 12:00:01')));
    }

    public function testWithoutAnExpiryDateItNeverExpires(): void
    {
        self::assertFalse((new Survey())->isExpired(new \DateTimeImmutable('2099-01-01')));
    }

    public function testIsCompletedOnceTheFormHasBeenSubmitted(): void
    {
        $survey = new Survey();
        $survey->setCompletedAt(new \DateTimeImmutable());

        self::assertTrue($survey->isCompleted());
    }
}
