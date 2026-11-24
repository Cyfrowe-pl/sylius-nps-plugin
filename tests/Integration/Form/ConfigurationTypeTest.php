<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Form;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Form\Type\ConfigurationType;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;

final class ConfigurationTypeTest extends IntegrationTestCase
{
    private FormFactoryInterface $formFactory;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $formFactory = self::getContainer()->get('form.factory');
        assert($formFactory instanceof FormFactoryInterface);
        $this->formFactory = $formFactory;

        $validator = self::getContainer()->get('validator');
        assert($validator instanceof ValidatorInterface);
        $this->validator = $validator;

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        $shop = new ShopFixture($this->entityManager, $cache);
        $shop->createChannel('FASHION_WEB', 'Fashion Web Store');
    }

    /** @return iterable<string, array{string, string}> */
    public static function outOfRangeProvider(): iterable
    {
        yield 'window opening past the last hour' => ['sendHourFrom', '24'];
        yield 'negative window opening' => ['sendHourFrom', '-1'];
        yield 'window closing at the start of the day' => ['sendHourTo', '0'];
        yield 'window closing past the end of the day' => ['sendHourTo', '25'];
        yield 'window closing when it opens' => ['sendHourTo', '10'];
        yield 'window closing before it opens' => ['sendHourTo', '9'];
        yield 'threshold above the scale' => ['notifyThreshold', '11'];
        yield 'link valid for no days' => ['linkValidityDays', '0'];
        yield 'negative delay' => ['delayDays', '-1'];
    }

    /** @dataProvider outOfRangeProvider */
    public function testRejectsValuesOutsideTheAllowedRange(string $field, string $value): void
    {
        $configuration = new Configuration();
        $form = $this->formFactory->create(ConfigurationType::class, $configuration, ['csrf_protection' => false]);

        $form->submit($this->validPayload([$field => $value]));

        self::assertFalse($form->isValid(), sprintf('Expected %s=%s to be rejected.', $field, $value));
    }

    public function testAcceptsARoundTheClockWindow(): void
    {
        $configuration = new Configuration();
        $form = $this->formFactory->create(ConfigurationType::class, $configuration, ['csrf_protection' => false]);

        $form->submit($this->validPayload(['sendHourFrom' => '0', 'sendHourTo' => '24']));

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testAcceptsTheDefaultsTheEntityShipsWith(): void
    {
        $configuration = new Configuration();
        $form = $this->formFactory->create(ConfigurationType::class, $configuration, ['csrf_protection' => false]);

        $form->submit($this->validPayload());

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testRequiresAnAlertAddressWhenAlertsAreOn(): void
    {
        $configuration = new Configuration();
        $configuration->setNotifyEnabled(true);
        $configuration->setNotifyEmail(null);
        $configuration->setChannelCode('FASHION_WEB');

        $violations = $this->validator->validate($configuration, null, ['sylius']);

        self::assertGreaterThan(0, $violations->count());
    }

    public function testAcceptsAMissingAlertAddressWhenAlertsAreOff(): void
    {
        $configuration = new Configuration();
        $configuration->setNotifyEnabled(false);
        $configuration->setNotifyEmail(null);
        $configuration->setChannelCode('FASHION_WEB');

        self::assertCount(0, $this->validator->validate($configuration, null, ['sylius']));
    }

    public function testStoresContentsUnderTheLocaleTheyWereEnteredIn(): void
    {
        $configuration = new Configuration();
        $form = $this->formFactory->create(ConfigurationType::class, $configuration, ['csrf_protection' => false]);

        $locales = array_keys($form->get('translations')->all());
        self::assertNotSame([], $locales, 'The settings form offers no locale to enter contents in.');

        $locale = (string) $locales[0];

        $form->submit($this->validPayload([
            'translations' => [
                $locale => [
                    'emailSubject' => 'How did we do?',
                    'mainQuestion' => 'How likely are you to recommend us?',
                    'thankYouText' => 'Thank you for your answer.',
                ],
            ],
        ]));

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        $id = $configuration->getId();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Configuration::class, $id);
        assert($reloaded instanceof Configuration);

        $translation = $reloaded->getTranslation($locale);

        self::assertSame($locale, $translation->getLocale());
        self::assertSame('How did we do?', $translation->getEmailSubject());
        self::assertSame('How likely are you to recommend us?', $translation->getMainQuestion());
        self::assertSame('Thank you for your answer.', $translation->getThankYouText());
    }

    public function testRendersEachLocaleAsASectionThatStartsCollapsed(): void
    {
        $twig = self::getContainer()->get('twig');
        assert($twig instanceof Environment);

        $form = $this->formFactory->create(ConfigurationType::class, new Configuration(), ['csrf_protection' => false]);

        $html = $twig->createTemplate('{{ form_row(form.translations) }}')->render(['form' => $form->createView()]);

        self::assertStringContainsString('ui styled fluid accordion', $html);
        self::assertSame(
            1,
            substr_count($html, 'class="title active"'),
            'Exactly one locale section should start open.',
        );
        self::assertSame(
            count($form->get('translations')->all()),
            substr_count($html, 'data-locale="'),
            'Every locale the form offers should get its own section.',
        );
    }

    public function testRejectsASecondConfigurationForTheSameChannel(): void
    {
        $existing = new Configuration();
        $existing->setChannelCode('FASHION_WEB');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $duplicate = new Configuration();
        $duplicate->setChannelCode('FASHION_WEB');

        $violations = $this->validator->validate($duplicate, null, ['sylius']);

        self::assertGreaterThan(0, $violations->count(), 'A duplicate channel should be caught by validation, not by the database.');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'channelCode' => 'FASHION_WEB',
            'enabled' => '1',
            'delayDays' => '5',
            'cooldownDays' => '90',
            'sendHourFrom' => '10',
            'sendHourTo' => '20',
            'linkValidityDays' => '30',
            'notifyEnabled' => '1',
            'notifyEmail' => 'notify@example.com',
            'notifyThreshold' => '6',
            'notifyWindowMinutes' => '30',
        ], $overrides);
    }
}
