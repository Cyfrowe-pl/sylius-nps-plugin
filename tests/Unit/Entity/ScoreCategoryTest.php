<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\Entity;

use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;
use PHPUnit\Framework\TestCase;

final class ScoreCategoryTest extends TestCase
{
    /** @return iterable<string, array{int, ScoreCategory}> */
    public static function scoreProvider(): iterable
    {
        yield 'the bottom of the scale' => [0, ScoreCategory::Detractor];
        yield 'the last detractor' => [6, ScoreCategory::Detractor];
        yield 'the first passive' => [7, ScoreCategory::Passive];
        yield 'the last passive' => [8, ScoreCategory::Passive];
        yield 'the first promoter' => [9, ScoreCategory::Promoter];
        yield 'the top of the scale' => [10, ScoreCategory::Promoter];
    }

    /** @dataProvider scoreProvider */
    public function testEveryScoreOnTheScaleFallsInOneBand(int $score, ScoreCategory $expected): void
    {
        self::assertSame($expected, ScoreCategory::of($score));
    }

    public function testAScoreOffTheScaleIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScoreCategory::of(11);
    }

    public function testTheBandsCoverTheWholeScaleWithoutOverlapping(): void
    {
        $covered = [];

        foreach (ScoreCategory::cases() as $category) {
            [$from, $to] = $category->scoreRange();

            foreach (range($from, $to) as $score) {
                self::assertArrayNotHasKey($score, $covered, sprintf('Score %d falls in two bands.', $score));
                $covered[$score] = $category;
            }
        }

        self::assertSame(range(0, 10), array_keys($covered), 'Every score from 0 to 10 has to belong somewhere.');
    }
}
