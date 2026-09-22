<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Services\PatientAccount;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Les comptes financiers des patients : ce qu'ils ont versé d'avance, ce que
 * ces avances ont payé, ce qu'il leur reste, et ce qu'ils doivent encore.
 */
final class PatientAccountController extends FinanceController
{
    public function index(Request $request, PatientAccount $accounts): View
    {
        $search = Text::clean(is_string($request->query('q')) ? $request->query('q') : null);
        $rows = $accounts->all($search);

        return view('finance::accounts.index', [
            'accounts' => $rows,
            'search' => $search,
            'totals' => [
                'deposited' => array_sum(array_column($rows, 'deposited')),
                'used' => array_sum(array_column($rows, 'used')),
                'balance' => array_sum(array_column($rows, 'balance')),
            ],
        ]);
    }

    public function show(string $patient, PatientAccount $accounts): View
    {
        $invoices = $accounts->invoices($patient);
        $movements = $accounts->movements($patient);

        $name = $movements->first()['model']->patient_name ?? $invoices->first()?->patient_name;

        return view('finance::accounts.show', [
            'patientId' => $patient,
            'patientName' => $name,
            'summary' => $accounts->summary($patient),
            'movements' => $movements,
            'invoices' => $invoices,
            'outstanding' => $invoices->sum(fn (Invoice $invoice): int => $invoice->balance()),
        ]);
    }
}
