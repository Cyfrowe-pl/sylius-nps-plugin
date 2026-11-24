<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\Entity;

use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use PHPUnit\Framework\TestCase;

final class SurveyStatusTest extends TestCase
{
    /** @return iterable<string, array{SurveyStatus, bool}> */
    public static function statusProvider(): iterable
    {
        yield 'pending is not counted' => [SurveyStatus::Pending, false];
        yield 'queued is not counted' => [SurveyStatus::Queued, false];
        yield 'sent is not counted' => [SurveyStatus::Sent, false];
        yield 'scored is counted' => [SurveyStatus::Scored, true];
        yield 'completed is counted' => [SurveyStatus::Completed, true];
        yield 'skipped is not counted' => [SurveyStatus::Skipped, false];
    }

    /** @dataProvider statusProvider */
    public function testTellsWhetherItCountsTowardsTheIndex(SurveyStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->countsTowardsIndex());
    }

    public function testCountsOnlyScoredAndCompletedTowardsTheIndex(): void
    {
        self::assertSame(
            [SurveyStatus::Scored, SurveyStatus::Completed],
            SurveyStatus::countedTowardsIndex(),
        );
    }
}
