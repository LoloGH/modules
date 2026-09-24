<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\ReportBuilder;
use Keneya\FinanceCaisse\Support\LedgerFilters;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapports financiers : période, filtres, type de rapport, et export CSV.
 * Toujours tout l'établissement : c'est un écran de pilotage
 * (`finance.reports.view`).
 */
final class ReportController extends FinanceController
{
    public function index(Request $request, ReportBuilder $builder): View
    {
        $filters = LedgerFilters::fromRequest($request);
        $type = ReportBuilder::type($request->query('type'));

        return view('finance::reports.index', [
            'filters' => $filters,
            'type' => $type,
            'types' => ReportBuilder::TYPES,
            'report' => $builder->build($type, $filters),
            'summary' => $builder->summary($filters),
            'methods' => PaymentMethod::query()->orderBy('name')->get(),
            'centers' => AnalyticCenter::query()->orderBy('name')->get(),
            'acts' => Act::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Le même rapport en CSV (séparateur « ; », encodage UTF-8 avec BOM :
     * s'ouvre tel quel dans un tableur réglé en français). Montants entiers,
     * sans séparateur de milliers, pour rester calculables.
     */
    public function export(Request $request, ReportBuilder $builder): StreamedResponse
    {
        $filters = LedgerFilters::fromRequest($request);
        $type = ReportBuilder::type($request->query('type'));
        $report = $builder->build($type, $filters);

        $filename = sprintf('rapport-%s-%s-%s.csv', $type, $filters->from->format('Ymd'), $filters->to->format('Ymd'));

        return response()->streamDownload(function () use ($report, $type, $filters): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [ReportBuilder::TYPES[$type].' · '.$filters->periodLabel()], ';');
            fputcsv($out, $report['columns'], ';');

            foreach ($report['rows'] as $row) {
                fputcsv($out, $row, ';');
            }

            fputcsv($out, $report['total'], ';');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
