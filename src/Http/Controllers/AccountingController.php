<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Services\AccountingExport;
use Keneya\FinanceCaisse\Support\LedgerFilters;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les exports comptables : les écritures du module, dans la forme que le
 * comptable reprendra dans son logiciel.
 *
 * L'écran montre d'abord ce qui sortira — le journal, la balance, et le
 * contrôle d'équilibre — avant de laisser télécharger. Un export qui ne
 * s'équilibre pas se voit à l'écran plutôt que chez le comptable.
 */
final class AccountingController extends FinanceController
{
    private const PREVIEW = 30;

    public function index(Request $request, AccountingExport $export): View
    {
        $filters = LedgerFilters::fromRequest($request);
        $entries = $export->entries($filters);

        return view('finance::accounting.index', [
            'filters' => $filters,
            'entries' => array_slice($entries, 0, self::PREVIEW),
            'count' => count($entries),
            'preview' => self::PREVIEW,
            'balance' => $export->balance($entries),
            'totals' => $export->totals($entries),
        ]);
    }

    /**
     * Le journal : une ligne par écriture, avec sa pièce et son centre.
     */
    public function journal(Request $request, AccountingExport $export): StreamedResponse
    {
        $filters = LedgerFilters::fromRequest($request);
        $entries = $export->entries($filters);

        return $this->csv(
            'journal-comptable-'.$filters->from->toDateString().'-'.$filters->to->toDateString().'.csv',
            ['Date', 'Journal', 'Pièce', 'Compte', 'Libellé', 'Débit', 'Crédit', 'Centre analytique'],
            array_map(static fn ($entry): array => $entry->toRow(), $entries),
        );
    }

    /**
     * La balance : un compte par ligne, débit, crédit et solde.
     */
    public function balance(Request $request, AccountingExport $export): StreamedResponse
    {
        $filters = LedgerFilters::fromRequest($request);
        $balance = $export->balance($export->entries($filters));

        return $this->csv(
            'balance-comptable-'.$filters->from->toDateString().'-'.$filters->to->toDateString().'.csv',
            ['Compte', 'Libellé', 'Débit', 'Crédit', 'Solde'],
            array_map(
                static fn (array $row): array => [$row['account'], $row['label'], $row['debit'], $row['credit'], $row['balance']],
                $balance,
            ),
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string|int>>  $rows
     */
    private function csv(string $name, array $columns, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'wb');

            // BOM : Excel lit l'UTF-8 sans le demander.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';');

            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
