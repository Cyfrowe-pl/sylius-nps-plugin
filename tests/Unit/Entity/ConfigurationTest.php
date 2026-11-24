<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Unit\Entity;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testShipsWithTheAgreedDefaults(): void
    {
        $configuration = new Configuration();

        self::assertTrue($configuration->isEnabled());
        self::assertSame(5, $configuration->getDelayDays());
        self::assertSame(90, $configuration->getCooldownDays());
        self::assertSame(30, $configuration->getLinkValidityDays());
        self::assertSame(10, $configuration->getSendHourFrom());
        self::assertSame(20, $configuration->getSendHourTo());
        self::assertSame(6, $configuration->getNotifyThreshold());
        self::assertSame(30, $configuration->getNotifyWindowMinutes());
    }
}
