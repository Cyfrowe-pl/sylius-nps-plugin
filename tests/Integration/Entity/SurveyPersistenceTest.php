<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Entity;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class SurveyPersistenceTest extends IntegrationTestCase
{
    public function testWhatTheAlertsRecordSurvivesARoundTrip(): void
    {
        $factory = new SurveyFactory($this->entityManager);
        $survey = $factory->createSurvey();
        $survey->setCriticalNotifiedAt(new \DateTimeImmutable('2026-09-20 09:00:00'));
        $survey->setCriticalCommentNotifiedAt(new \DateTimeImmutable('2026-09-20 10:00:00'));
        $survey->setCriticalCorrectionNotifiedAt(new \DateTimeImmutable('2026-09-20 11:00:00'));
        $survey->setCriticalAttempts(2);
        $this->entityManager->flush();

        $id = $survey->getId();
        $this->entityManager->clear();

        $saved = $this->entityManager->find(Survey::class, $id);
        assert($saved instanceof Survey);

        self::assertSame('2026-09-20 09:00:00', $saved->getCriticalNotifiedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-20 10:00:00', $saved->getCriticalCommentNotifiedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-20 11:00:00', $saved->getCriticalCorrectionNotifiedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(2, $saved->getCriticalAttempts());
    }

    public function testARecordThatWasNeverAlertedAboutCountsNoAttempts(): void
    {
        $factory = new SurveyFactory($this->entityManager);
        $survey = $factory->createSurvey();
        $this->entityManager->flush();

        $id = $survey->getId();
        $this->entityManager->clear();

        $saved = $this->entityManager->find(Survey::class, $id);
        assert($saved instanceof Survey);

        // The column is not nullable, so a record that has never been through the command has to
        // come back as nought rather than as null.
        self::assertSame(0, $saved->getCriticalAttempts());
        self::assertNull($saved->getCriticalNotifiedAt());
    }
}
