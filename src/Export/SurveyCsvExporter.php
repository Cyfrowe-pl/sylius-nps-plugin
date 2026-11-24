<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Export;

use Cyfrowe\SyliusNpsPlugin\Entity\HandlingStatus;
use Cyfrowe\SyliusNpsPlugin\Entity\ScoreCategory;
use Cyfrowe\SyliusNpsPlugin\Entity\SurveyStatus;
use Cyfrowe\SyliusNpsPlugin\Grid\FilteredSurveys;
use Doctrine\ORM\AbstractQuery;
use Psr\Clock\ClockInterface;
use Sylius\Component\Grid\Parameters;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin list as a file, holding exactly the records the list is showing.
 *
 * Written straight to the output as the rows arrive, and read back as plain values rather than as
 * records, so the size of the file is a question of how long it takes rather than of how much
 * memory the shop has.
 */
final class SurveyCsvExporter
{
    /**
     * A cell opening with one of these is a formula rather than a value, to Excel and to Google
     * Sheets alike.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * How many records go out between one flush and the next.
     */
    private const FLUSH_EVERY = 500;

    private const HEADERS = [
        'cyfrowe_nps.ui.scored_at',
        'cyfrowe_nps.ui.score',
        'cyfrowe_nps.ui.category',
        'cyfrowe_nps.ui.customer_email',
        'cyfrowe_nps.ui.order_number',
        'cyfrowe_nps.ui.order_total',
        'cyfrowe_nps.ui.currency',
        'cyfrowe_nps.ui.shipping_method',
        'cyfrowe_nps.ui.channel',
        'cyfrowe_nps.ui.comment',
        'cyfrowe_nps.ui.sent_at',
        'cyfrowe_nps.ui.handling_status',
        'cyfrowe_nps.ui.survey_status',
    ];

    public function __construct(
        private readonly FilteredSurveys $filteredSurveys,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly string $delimiter,
        private readonly string $enclosure,
        private readonly string $decimalSeparator,
        private readonly bool $bom,
    ) {
    }

    public function export(Parameters $parameters): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($parameters): void {
            $handle = fopen('php://output', 'wb');
            assert(false !== $handle);

            // One buffer for the whole file rather than one per row: fputcsv is the only thing
            // that knows how to quote a value, but it insists on writing a line ending of its own.
            $buffer = fopen('php://memory', 'r+b');
            assert(false !== $buffer);

            // Excel reads the file as UTF-8 only when it starts with this, and most other tools
            // read it as a stray character - which is why it is a setting and not a default.
            if ($this->bom) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $this->write($handle, $buffer, array_map($this->translator->trans(...), self::HEADERS));

            $written = 0;

            foreach ($this->rows($parameters) as $row) {
                $this->write($handle, $buffer, $this->cells($row));

                if (0 === ++$written % self::FLUSH_EVERY) {
                    $this->handOver();
                }
            }

            fclose($buffer);
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('nps-responses-%s.csv', $this->clock->now()->format('Y-m-d')),
        ));

        return $response;
    }

    /**
     * Reading the records a few at a time is only half of it: written to php://output under an
     * output buffer, the whole file would still pile up in memory before anything left the
     * process. How large that buffer is depends on the shop's php.ini, so it is emptied here
     * rather than assumed away.
     */
    private function handOver(): void
    {
        if (0 !== ob_get_level()) {
            ob_flush();
        }

        flush();
    }

    /**
     * @param resource $handle
     * @param resource $buffer
     * @param list<string> $cells
     */
    private function write($handle, $buffer, array $cells): void
    {
        ftruncate($buffer, 0);
        rewind($buffer);

        // RFC 4180 has no escape character, and PHP's default - a backslash - makes fputcsv wrap
        // every value containing one in quotes the format never asked for. Emptied, the output
        // stays plain; quoting still happens wherever the format does require it.
        fputcsv($buffer, $cells, $this->delimiter, $this->enclosure, '');
        rewind($buffer);

        $line = (string) stream_get_contents($buffer);

        // RFC 4180 separates records with CRLF and fputcsv appends a bare newline, so its last
        // byte - and only its last byte - is replaced.
        fwrite($handle, substr($line, 0, -1) . "\r\n");
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function rows(Parameters $parameters): iterable
    {
        $queryBuilder = $this->filteredSurveys->forCriteria($parameters);
        $alias = $queryBuilder->getRootAliases()[0];

        return $queryBuilder
            ->select(sprintf(
                '%1$s.scoreSetAt AS scoreSetAt, %1$s.score AS score, customer.email AS email, ' .
                '%1$s.orderNumber AS orderNumber, %1$s.orderTotal AS orderTotal, ' .
                '%1$s.currencyCode AS currencyCode, %1$s.shippingMethodName AS shippingMethodName, ' .
                '%1$s.channelCode AS channelCode, %1$s.comment AS comment, %1$s.sentAt AS sentAt, ' .
                '%1$s.handlingStatus AS handlingStatus, %1$s.status AS status',
                $alias,
            ))
            ->getQuery()
            ->toIterable([], AbstractQuery::HYDRATE_ARRAY)
        ;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function cells(array $row): array
    {
        $score = $row['score'] ?? null;
        $total = $row['orderTotal'] ?? null;
        $handlingStatus = $row['handlingStatus'] ?? null;
        $status = $row['status'] ?? null;

        return [
            $this->moment($row['scoreSetAt'] ?? null),
            null === $score ? '' : (string) $score,
            null === $score ? '' : $this->text($this->translate('categories', ScoreCategory::of((int) $score)->value)),
            $this->text($row['email'] ?? null),
            $this->text($row['orderNumber'] ?? null),
            null === $total ? '' : number_format((int) $total / 100, 2, $this->decimalSeparator, ''),
            $this->text($row['currencyCode'] ?? null),
            $this->text($row['shippingMethodName'] ?? null),
            $this->text($row['channelCode'] ?? null),
            $this->text($row['comment'] ?? null),
            $this->moment($row['sentAt'] ?? null),
            $handlingStatus instanceof HandlingStatus ? $this->text($this->translate('handling_statuses', $handlingStatus->value)) : '',
            $status instanceof SurveyStatus ? $this->text($this->translate('survey_statuses', $status->value)) : '',
        ];
    }

    /**
     * Quoting a value keeps the file's structure intact but says nothing about what the cell then
     * means: a parser strips the quotes and hands the spreadsheet a string that still opens with
     * an equals sign. The apostrophe is the marker spreadsheets read as "treat this as text", so
     * the comment stays legible and =HYPERLINK or =IMPORTXML stays a comment. The text in these
     * columns is written by customers; the person opening the file works here.
     */
    private function text(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if ('' !== $value && in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function translate(string $group, string $value): string
    {
        return $this->translator->trans(sprintf('cyfrowe_nps.ui.%s.%s', $group, $value));
    }

    private function moment(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : '';
    }
}
