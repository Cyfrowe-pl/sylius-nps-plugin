<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Functional;

use Cyfrowe\SyliusNpsPlugin\Entity\Configuration;
use Cyfrowe\SyliusNpsPlugin\Form\Type\ConfigurationType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Mime\Email;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class TestEmailButtonTest extends FunctionalTestCase
{
    private const SAVED_SUBJECT = 'Saved wording nobody wants to preview';

    private const TYPED_SUBJECT = 'Wording typed in just now';

    public function testTheTestEmailUsesTheWordingOnScreenRatherThanTheSavedOne(): void
    {
        $this->logIn();
        $this->saveAConfigurationWithItsOwnSubject();

        $this->client->request('POST', '/admin/nps/settings/test-email', $this->payload());

        self::assertResponseRedirects();

        $email = self::getMailerMessage();
        assert($email instanceof Email);
        self::assertSame(self::TYPED_SUBJECT, $email->getSubject());
        self::assertSame('quality@example.com', $email->getTo()[0]->getAddress());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sylius-flash-message', 'The test email has been sent.');
    }

    /**
     * The edit screen carries `_method=PUT` for the update, and the button posts that very form, so
     * the request reaches this route as PUT once Symfony applies the method override.
     */
    public function testTheButtonWorksOnTheEditScreen(): void
    {
        $this->logIn();
        $configuration = $this->saveAConfigurationWithItsOwnSubject();

        $crawler = $this->client->request('GET', sprintf('/admin/nps/settings/%d/edit', (int) $configuration->getId()));
        $form = $crawler->selectButton('cyfrowe_nps_configuration_sendTestEmail')->form();

        foreach ($this->localesTheFormOffers() as $locale) {
            $form[sprintf('cyfrowe_nps_configuration[translations][%s][emailSubject]', $locale)] = self::TYPED_SUBJECT;
        }
        $form['cyfrowe_nps_configuration[testEmailRecipient]'] = 'quality@example.com';

        $this->client->submit($form);

        self::assertResponseRedirects();

        $email = self::getMailerMessage();
        assert($email instanceof Email);
        self::assertSame(self::TYPED_SUBJECT, $email->getSubject());
    }

    public function testAnInvalidAddressSendsNothing(): void
    {
        $this->logIn();

        $payload = $this->payload();
        $payload['cyfrowe_nps_configuration']['testEmailRecipient'] = 'not-an-address';

        $this->client->request('POST', '/admin/nps/settings/test-email', $payload);

        self::assertResponseRedirects();
        self::assertSame([], self::getMailerEvents());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sylius-flash-message', 'Enter a valid address to send the test to.');
    }

    public function testTheLinksInATestEmailCannotScoreARealSurvey(): void
    {
        $this->logIn();
        $survey = (new SurveyFactory($this->entityManager))->createSurvey();
        $survey->setToken('a-real-survey-token');
        $this->entityManager->flush();

        $this->client->request('POST', '/admin/nps/settings/test-email', $this->payload());

        $email = self::getMailerMessage();
        assert($email instanceof Email);
        self::assertStringNotContainsString(
            'a-real-survey-token',
            (string) $email->getHtmlBody(),
            'A test email must never carry a token that belongs to a customer.',
        );
    }

    public function testAForgedRequestWithoutATokenSendsNothing(): void
    {
        $this->logIn();

        $payload = $this->payload();
        unset($payload['cyfrowe_nps_configuration']['_token']);

        $this->client->request('POST', '/admin/nps/settings/test-email', $payload);

        self::assertSame(
            [],
            self::getMailerEvents(),
            'Without this the shop would send an outsider\'s wording from its own address.',
        );
    }

    public function testAForgedRequestWithTheWrongTokenSendsNothing(): void
    {
        $this->logIn();

        $payload = $this->payload();
        $payload['cyfrowe_nps_configuration']['_token'] = 'not-the-token';

        $this->client->request('POST', '/admin/nps/settings/test-email', $payload);

        self::assertSame([], self::getMailerEvents());
    }

    /**
     * The route takes PUT for the edit screen, so a forged request can arrive that way too. The
     * redirect and the flash prove it reached the action rather than being turned away by routing.
     */
    public function testAForgedRequestSentAsPutSendsNothing(): void
    {
        $this->logIn();

        $payload = $this->payload();
        unset($payload['cyfrowe_nps_configuration']['_token']);
        $payload['_method'] = 'PUT';

        $this->client->request('POST', '/admin/nps/settings/test-email', $payload);

        self::assertResponseRedirects();
        self::assertSame([], self::getMailerEvents());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.sylius-flash-message', 'The test email could not be built.');
    }

    public function testItWillNotSendTheVisitorOffToAnotherSite(): void
    {
        $this->logIn();

        $this->client->request(
            'POST',
            '/admin/nps/settings/test-email',
            $this->payload(),
            server: ['HTTP_REFERER' => 'https://elsewhere.example.com/landing'],
        );

        self::assertResponseRedirects('/admin/nps/settings/');
    }

    /**
     * Read off the real form rather than minted from the container, which also proves the settings
     * screen ships a token in the first place.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/nps/settings/new');
        $token = $crawler->filter('input[name="cyfrowe_nps_configuration[_token]"]')->attr('value');

        assert(is_string($token));

        return $token;
    }

    private function saveAConfigurationWithItsOwnSubject(): Configuration
    {
        $configuration = new Configuration();
        $configuration->setChannelCode('NPS_FUNCTIONAL');

        foreach ($this->localesTheFormOffers() as $locale) {
            $configuration->getTranslation($locale)->setEmailSubject(self::SAVED_SUBJECT);
        }

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }

    /**
     * Asking the form rather than assuming: the shop this runs against decides which locales exist.
     *
     * @return list<string>
     */
    private function localesTheFormOffers(): array
    {
        $formFactory = self::getContainer()->get('form.factory');
        assert($formFactory instanceof FormFactoryInterface);

        $form = $formFactory->create(ConfigurationType::class, new Configuration());

        return array_map('strval', array_keys($form->get('translations')->all()));
    }

    public function testAVisitorWhoIsNotSignedInSendsNothing(): void
    {
        $this->client->request('POST', '/admin/nps/settings/test-email', [
            'cyfrowe_nps_configuration' => [
                'channelCode' => 'NPS_FUNCTIONAL',
                'testEmailRecipient' => 'outsider@example.com',
            ],
        ]);

        self::assertSame([], self::getMailerEvents());
        self::assertResponseStatusCodeSame(302, 'An anonymous request has to be turned away, not answered.');
    }

    /** @return array<string, array<string, mixed>> */
    private function payload(): array
    {
        $translations = [];

        foreach ($this->localesTheFormOffers() as $locale) {
            $translations[$locale] = ['emailSubject' => self::TYPED_SUBJECT];
        }

        return [
            'cyfrowe_nps_configuration' => [
                'channelCode' => 'NPS_FUNCTIONAL',
                'enabled' => '1',
                'delayDays' => '5',
                'cooldownDays' => '90',
                'sendHourFrom' => '10',
                'sendHourTo' => '20',
                'linkValidityDays' => '30',
                'notifyEnabled' => '0',
                'notifyThreshold' => '6',
                'notifyWindowMinutes' => '30',
                'translations' => $translations,
                'testEmailRecipient' => 'quality@example.com',
                '_token' => $this->csrfToken(),
            ],
        ];
    }
}
