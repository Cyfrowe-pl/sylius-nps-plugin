<?php

declare(strict_types=1);

namespace Cyfrowe\SyliusNpsPlugin\Controller\Admin;

use Cyfrowe\SyliusNpsPlugin\Export\SurveyCsvExporter;
use Sylius\Component\Grid\Parameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The filters arrive in the query string exactly as the list received them, so the file answers
 * for the same set of records the screen was showing.
 */
#[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
final class ExportCsvAction
{
    public function __construct(private readonly SurveyCsvExporter $exporter)
    {
    }

    public function __invoke(Request $request): Response
    {
        return $this->exporter->export(new Parameters($request->query->all()));
    }
}
