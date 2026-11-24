<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Mailer;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertData;
use Cyfrowe\SyliusNpsPlugin\Mailer\CriticalAlertKind;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Cache\CacheInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\ShopFixture;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class CriticalAlertTest extends IntegrationTestCase
{
    private const CHANNEL = 'ALERT_TEST';

    private const RECIPIENT = 'complaints@example.com';

    private SenderInterface $sender;

    private CriticalAlertData $data;

    private SurveyFactory $factory;

    private ChannelInterface $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $sender = self::getContainer()->get('sylius.email_sender');
        assert($sender instanceof SenderInterface);
        $this->sender = $sender;

        // Built here rather than pulled from the container: nothing injects it until the sender
        // lands, so the container inlines it away and fetching it by id fails.
        $layout = self::getContainer()->getParameter('cyfrowe_nps.email.layout');
        assert(is_string($layout));
        $this->data = new CriticalAlertData($layout);

        $cache = self::getContainer()->get('cache.app');
        assert($cache instanceof CacheInterface);

        $this->channel = (new ShopFixture($this->entityManager, $cache))->createChannel(self::CHANNEL, 'Alert test');
        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testTheAlertCarriesWhatAnOperatorNeedsToDecideWithoutOpeningThePanel(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setComment('The courier left it with a neighbour.');
        $survey->setOrderTotal(45900);
        $survey->setCurrencyCode('PLN');
        $survey->setShippingMethodName('Express courier');

        $body = $this->bodyOf($survey, CriticalAlertKind::Primary);

        self::assertStringContainsString('2', $body);
        self::assertStringContainsString('The courier left it with a neighbour.', $body);
        self::assertStringContainsString((string) $survey->getOrderNumber(), $body);
        self::assertStringContainsString('Express courier', $body);
        self::assertStringContainsString(sprintf('/admin/nps/responses/%d', (int) $survey->getId()), $body);
    }

    public function testAScoreWithNoFormBehindItIsMarkedProvisional(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setExpiresAt(new \DateTimeImmutable('2026-10-05 10:00:00'));

        $body = $this->bodyOf($survey, CriticalAlertKind::Primary);

        self::assertStringContainsString('05.10.2026', $body, 'The operator has to know how long the score can still move.');
        self::assertStringContainsString('Ocena wstępna', $body);
        self::assertStringContainsString('nie zostawił komentarza', $body);
    }

    public function testAScoreWithTheFormBehindItIsMarkedFinal(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setCompletedAt(new \DateTimeImmutable('2026-09-20 12:00:00'));

        self::assertStringContainsString('Ocena finalna', $this->bodyOf($survey, CriticalAlertKind::Primary));
    }

    public function testTheCorrectionSaysTheCustomerChangedTheirMind(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setScore(8);

        $email = $this->send($survey, CriticalAlertKind::Correction);

        self::assertStringContainsString('skorygowana na 8', (string) $email->getSubject());
        self::assertStringContainsString('nie wymaga już reakcji', (string) $email->getHtmlBody());
    }

    public function testTheFollowUpPointsAtTheCommentThatArrivedLate(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setComment('Adding to my earlier score.');

        $email = $this->send($survey, CriticalAlertKind::Comment);

        self::assertStringContainsString('Komentarz do oceny', (string) $email->getSubject());
        self::assertStringContainsString('Adding to my earlier score.', (string) $email->getHtmlBody());
    }

    public function testAFlaggedRecordIsAnnotatedRatherThanHidden(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setBotSuspected(true);

        $body = $this->bodyOf($survey, CriticalAlertKind::Primary);

        self::assertStringContainsString('diagnostyczna', $body, 'The flag belongs in the wording, never in the decision.');
    }

    public function testTheAlertIsWrittenInTheLanguageOfTheShopNotOfTheCustomer(): void
    {
        $survey = $this->criticalAnswer();
        $survey->setLocaleCode('de_DE');

        // The channel fixture is Polish, so a survey answered in German still alerts in Polish.
        $body = $this->bodyOf($survey, CriticalAlertKind::Primary);

        self::assertStringContainsString('Klient ocenił swoje zamówienie', $body);
        self::assertStringNotContainsString('A customer rated their order', $body);
    }

    private function bodyOf(Survey $survey, CriticalAlertKind $kind): string
    {
        return (string) $this->send($survey, $kind)->getHtmlBody();
    }

    private function send(Survey $survey, CriticalAlertKind $kind): Email
    {
        $this->entityManager->flush();

        $this->sender->send(
            CriticalAlertData::EMAIL_CODE,
            [self::RECIPIENT],
            $this->data->for($survey, $this->channel, $kind, 'en_US'),
        );

        $email = self::getMailerMessage();
        assert($email instanceof Email);

        return $email;
    }

    private function criticalAnswer(): Survey
    {
        $survey = $this->factory->createSurvey(channelCode: self::CHANNEL);
        $survey->setScore(2);
        $survey->setScoreSetAt(new \DateTimeImmutable('2026-09-20 10:00:00'));
        $survey->setOrderNumber('ORD-48198');

        return $survey;
    }
}
