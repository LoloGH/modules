<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\ReportBuilder;
use Keneya\FinanceCaisse\Support\LedgerFilters;
use Keneya\FinanceCaisse\Support\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapports financiers : période, filtres, type de rapport, et export tableur.
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
     * Le même rapport en classeur : de vraies colonnes, un en-tête figé, des
     * montants qu'Excel additionne.
     *
     * Le CSV d'avant s'ouvrait en une seule colonne dès que le tableur n'était
     * pas réglé sur le point-virgule, et il fallait redécouper le rapport à la
     * main pour le relire.
     */
    public function export(Request $request, ReportBuilder $builder): StreamedResponse
    {
        $filters = LedgerFilters::fromRequest($request);
        $type = ReportBuilder::type($request->query('type'));
        $report = $builder->build($type, $filters);

        return Spreadsheet::download(
            sprintf('rapport-%s-%s-%s.xls', $type, $filters->from->format('Ymd'), $filters->to->format('Ymd')),
            ReportBuilder::TYPES[$type],
            $report['columns'],
            $report['rows'],
            $report['total'],
            $filters->periodLabel().' · '.Finance::facility()['name'],
        );
    }
}
