<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\Nps;

use Cyfrowe\SyliusNpsPlugin\Nps\NpsCalculator;
use PHPUnit\Framework\TestCase;

final class NpsCalculatorTest extends TestCase
{
    private NpsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new NpsCalculator();
    }

    public function testNothingAnsweredIsNotTheSameAsAZeroScore(): void
    {
        self::assertNull(
            $this->calculator->index(0, 0, 0),
            'Zero is a real result - as many promoters as detractors - so an empty set must not report it.',
        );
    }

    public function testOnlyPromotersGiveTheHighestPossibleIndex(): void
    {
        self::assertSame(100, $this->calculator->index(10, 0, 10));
    }

    public function testOnlyDetractorsGiveTheLowestPossibleIndex(): void
    {
        self::assertSame(-100, $this->calculator->index(0, 10, 10));
    }

    public function testOnlyPassivesGiveZero(): void
    {
        self::assertSame(0, $this->calculator->index(0, 0, 10), 'Passives count towards the total and nothing else.');
    }

    public function testPromotersAndDetractorsCancelOut(): void
    {
        self::assertSame(0, $this->calculator->index(5, 5, 10));
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function roundingProvider(): iterable
    {
        yield 'a third promoters' => [1, 0, 3, 33];
        yield 'two thirds promoters' => [2, 0, 3, 67];
        yield 'a third detractors' => [0, 1, 3, -33];
        yield 'six of seven promoters' => [6, 1, 7, 71];
    }

    /** @dataProvider roundingProvider */
    public function testTheIndexIsRoundedToAWholeNumber(int $promoters, int $detractors, int $answers, int $expected): void
    {
        self::assertSame($expected, $this->calculator->index($promoters, $detractors, $answers));
    }

    public function testPassivesAreCountedInTheDenominator(): void
    {
        // Nine promoters and one passive: the passive drags the index down from 100 even though it
        // is neither a promoter nor a detractor.
        self::assertSame(90, $this->calculator->index(9, 0, 10));
    }

    public function testShareOfNothingIsZeroRatherThanAnError(): void
    {
        self::assertSame(0.0, $this->calculator->share(0, 0));
    }

    public function testShareIsGivenToOneDecimal(): void
    {
        self::assertSame(33.3, $this->calculator->share(1, 3));
    }

    public function testRateOfNothingSentIsUnknownRatherThanZero(): void
    {
        self::assertNull($this->calculator->rate(0, 0));
    }

    public function testRateIsGivenToOneDecimal(): void
    {
        self::assertSame(66.7, $this->calculator->rate(2, 3));
    }
}
