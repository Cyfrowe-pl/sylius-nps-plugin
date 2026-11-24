<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Entity;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationInterface;
use Cyfrowe\SyliusNpsPlugin\Entity\ConfigurationTranslation;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;

final class ConfigurationTranslationTest extends IntegrationTestCase
{
    /**
     * Guards the translations mapping: Sylius' ORMTranslatableListener keys the collection by
     * locale, and it only does so for entities that do not map the association themselves.
     * Map it by hand and this test fails with an empty subject and a duplicate translation row.
     */
    public function testReadsBackATranslationSavedUnderItsLocale(): void
    {
        $configuration = $this->createConfiguration('TEST_CHANNEL');
        $configuration->setCurrentLocale('pl_PL');
        $configuration->setFallbackLocale('pl_PL');
        $configuration->getTranslation()->setEmailSubject('Jak oceniasz zakupy?');

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();
        $id = $configuration->getId();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Configuration::class, $id);
        self::assertInstanceOf(ConfigurationInterface::class, $reloaded);

        $reloaded->setCurrentLocale('pl_PL');
        $reloaded->setFallbackLocale('pl_PL');

        self::assertSame('Jak oceniasz zakupy?', $reloaded->getTranslation()->getEmailSubject());
        self::assertCount(1, $reloaded->getTranslations());
    }

    public function testKeepsOneTranslationPerLocale(): void
    {
        $configuration = $this->createConfiguration('TEST_CHANNEL_MULTI');
        $configuration->addTranslation($this->createTranslation('pl_PL', 'Polski temat'));
        $configuration->addTranslation($this->createTranslation('en_US', 'English subject'));

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();
        $id = $configuration->getId();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Configuration::class, $id);
        self::assertInstanceOf(ConfigurationInterface::class, $reloaded);
        $reloaded->setFallbackLocale('pl_PL');

        self::assertCount(2, $reloaded->getTranslations());

        $reloaded->setCurrentLocale('en_US');
        self::assertSame('English subject', $reloaded->getTranslation()->getEmailSubject());

        $reloaded->setCurrentLocale('pl_PL');
        self::assertSame('Polski temat', $reloaded->getTranslation()->getEmailSubject());
    }

    /**
     * Worth knowing before building the settings form: asking for a locale that has no
     * translation yields the fallback one, not a fresh empty translation. Editing what comes
     * back therefore edits the fallback.
     */
    public function testFallsBackToTheFallbackLocaleInsteadOfCreatingATranslation(): void
    {
        $configuration = $this->createConfiguration('TEST_CHANNEL_FALLBACK');
        $configuration->addTranslation($this->createTranslation('pl_PL', 'Polski temat'));
        $configuration->setFallbackLocale('pl_PL');
        $configuration->setCurrentLocale('de_DE');

        self::assertSame('Polski temat', $configuration->getTranslation()->getEmailSubject());
        self::assertCount(1, $configuration->getTranslations());
    }

    /**
     * A second flush must not append another row for a locale that already has one.
     */
    public function testDoesNotDuplicateATranslationOnASecondFlush(): void
    {
        $configuration = $this->createConfiguration('TEST_CHANNEL_REFLUSH');
        $configuration->setCurrentLocale('pl_PL');
        $configuration->setFallbackLocale('pl_PL');
        $configuration->getTranslation()->setEmailSubject('Pierwszy temat');

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();
        $id = $configuration->getId();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Configuration::class, $id);
        self::assertInstanceOf(ConfigurationInterface::class, $reloaded);
        $reloaded->setCurrentLocale('pl_PL');
        $reloaded->setFallbackLocale('pl_PL');
        $reloaded->getTranslation()->setEmailSubject('Drugi temat');

        $this->entityManager->flush();
        $this->entityManager->clear();

        $again = $this->entityManager->find(Configuration::class, $id);
        self::assertInstanceOf(ConfigurationInterface::class, $again);
        $again->setCurrentLocale('pl_PL');
        $again->setFallbackLocale('pl_PL');

        self::assertCount(1, $again->getTranslations());
        self::assertSame('Drugi temat', $again->getTranslation()->getEmailSubject());
    }

    private function createTranslation(string $locale, string $emailSubject): ConfigurationTranslation
    {
        $translation = new ConfigurationTranslation();
        $translation->setLocale($locale);
        $translation->setEmailSubject($emailSubject);

        return $translation;
    }

    private function createConfiguration(string $channelCode): Configuration
    {
        $configuration = new Configuration();
        $configuration->setChannelCode($channelCode);

        return $configuration;
    }
}
