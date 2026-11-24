<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Functional;

use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class AdminExportTest extends FunctionalTestCase
{
    private const LIST = '/admin/nps/responses/';

    private const EXPORT = '/admin/nps/responses/export.csv';

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testTheFileIsOfferedAsADownload(): void
    {
        $this->logIn();
        $this->answer('ORD-ONE', 9);
        $this->entityManager->flush();

        $this->client->request('GET', self::EXPORT);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('nps-responses-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testTheFileHoldsOnlyWhatTheFiltersLeft(): void
    {
        $this->logIn();
        $this->answer('ORD-WANTED', 9)->setChannelCode('WANTED');
        $this->answer('ORD-ELSEWHERE', 9)->setChannelCode('OTHER');
        $this->entityManager->flush();

        $this->client->request('GET', self::EXPORT, ['criteria' => ['channelCode' => 'WANTED']]);

        $csv = $this->body();

        self::assertStringContainsString('ORD-WANTED', $csv);
        self::assertStringNotContainsString('ORD-ELSEWHERE', $csv);
    }

    public function testTheButtonOnTheListCarriesTheFiltersInUse(): void
    {
        $this->logIn();
        $this->answer('ORD-ONE', 9);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', self::LIST, ['criteria' => ['category' => 'promoter']]);

        $href = $crawler->filter(sprintf('a[href*="%s"]', self::EXPORT))->attr('href');

        self::assertIsString($href);
        self::assertStringContainsString('criteria%5Bcategory%5D=promoter', $href, 'A file that ignores the filters on screen is the whole trap this guards against.');
    }

    public function testTheButtonIsThereWithoutAnyFilters(): void
    {
        $this->logIn();
        $crawler = $this->client->request('GET', self::LIST);

        self::assertCount(1, $crawler->filter(sprintf('a[href*="%s"]', self::EXPORT)));
    }

    public function testTheFileHoldsEveryPageAndNotJustTheOneBeingRead(): void
    {
        $this->logIn();

        for ($i = 0; $i < 30; ++$i) {
            $this->answer(sprintf('ORD-%05d', $i), 9);
        }
        $this->entityManager->flush();

        // The list shows 25 at a time, so this is the second page - and the file still has to be
        // the whole filtered set rather than what happens to be on screen.
        $this->client->request('GET', self::EXPORT, ['page' => 2, 'limit' => 25]);

        $rows = array_filter(
            explode("\r\n", $this->body()),
            static fn (string $line): bool => str_contains($line, 'ORD-'),
        );

        self::assertCount(30, $rows);
        self::assertStringContainsString('ORD-00000', $this->body());
        self::assertStringContainsString('ORD-00029', $this->body());
    }

    public function testTheHeadersFollowTheLanguageOfThePanel(): void
    {
        $this->logIn('pl_PL');
        $this->answer('ORD-ONE', 9);
        $this->entityManager->flush();

        $this->client->request('GET', self::EXPORT);

        $csv = $this->body();

        self::assertStringContainsString('Data oceny', $csv, 'The headers come from translations, not from a hard coded language.');
        self::assertStringContainsString('Waluta', $csv);
        self::assertStringNotContainsString('Scored at', $csv);
    }

    public function testTheFileStatesTheStatusOfEverySurvey(): void
    {
        $this->logIn('pl_PL');
        $this->factory->createSurvey(status: SurveyStatus::Skipped)->setOrderNumber('ORD-SKIPPED');
        $this->entityManager->flush();

        $this->client->request('GET', self::EXPORT);

        $lines = explode("\r\n", trim($this->body()));

        self::assertStringEndsWith('"Status ankiety"', $lines[0]);
        self::assertStringEndsWith('Pominięta', $lines[1]);
    }

    /**
     * Read through BrowserKit rather than off the response: the body is streamed, and a streamed
     * response sends itself once - by the time the test looks, it is already gone.
     */
    private function body(): string
    {
        return $this->client->getInternalResponse()->getContent();
    }

    private function answer(string $orderNumber, int $score): Survey
    {
        $survey = $this->factory->createSurvey();
        $survey->setOrderNumber($orderNumber);
        $survey->setScore($score);
        $survey->setScoreSetAt(new \DateTimeImmutable('2026-09-02 10:00:00'));

        return $survey;
    }
}
