<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Actions\CancelInvoice;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Http\Requests\CancelMovementRequest;
use Keneya\FinanceCaisse\Http\Requests\InvoiceRequest;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Factures : liste, émission, fiche, annulation. L'encaissement d'une facture
 * se fait dans la session de caisse (bouton « Encaisser »), qui en tient le
 * solde à jour.
 */
final class InvoiceController extends FinanceController
{
    private const PER_PAGE = 50;

    /** Onglets de statut : « tous » puis les statuts de facture. */
    public const TABS = ['tous' => 'Toutes'] + [
        Invoice::STATUS_UNPAID => 'Impayées',
        Invoice::STATUS_PARTIAL => 'Partielles',
        Invoice::STATUS_PAID => 'Payées',
        Invoice::STATUS_CANCELLED => 'Annulées',
        Invoice::STATUS_REFUNDED => 'Remboursées',
    ];

    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('statut'), self::TABS) ? (string) $request->query('statut') : 'tous';
        $search = Text::clean(is_string($request->query('q')) ? $request->query('q') : null);

        $base = Invoice::query()->when($search, function (Builder $query) use ($search): void {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $w) => $w
                ->where('number', 'like', $like)
                ->orWhere('patient_name', 'like', $like)
                ->orWhere('patient_id', 'like', $like));
        });

        // Les chiffres portent sur les factures vivantes (ni annulées ni
        // remboursées) de la recherche en cours.
        $live = (clone $base)->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED]);

        return view('finance::invoices.index', [
            'invoices' => (clone $base)
                ->when($status !== 'tous', fn (Builder $q) => $q->where('status', $status))
                ->latest('created_at')->latest('id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'status' => $status,
            'search' => $search,
            'tabs' => self::TABS,
            'counts' => (clone $base)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'stats' => [
                'total' => (int) (clone $live)->sum('total'),
                'paid' => (int) (clone $live)->sum('paid'),
                // Ce que doivent les patients : leur part, plus les rejets.
                'due' => (int) (clone $live)->sum('patient_share') + (int) (clone $live)->sum('insurer_rejected'),
            ],
        ]);
    }

    public function create(): View
    {
        return view('finance::invoices.create', [
            // Seuls les actes actifs ET tarifés peuvent être facturés.
            'acts' => Act::query()->active()->with(['center', 'standardTariff'])->get()
                ->filter(fn (Act $act): bool => $act->standardTariff !== null)
                ->values(),
            'rows' => max(5, count((array) old('lines', []))),
            'insurers' => Insurer::query()->active()->get(),
        ]);
    }

    public function store(InvoiceRequest $request, CreateInvoice $action): RedirectResponse
    {
        $invoice = $action->handle(
            $request->validated('patient_id'),
            $request->validated('patient_name'),
            $request->invoiceLines(),
            $request->validated('note'),
            $this->user($request),
            $request->coverage(),
        );

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('finance_print', ['url' => route('finance.invoices.print', $invoice).'?auto=1', 'label' => 'Imprimer la facture'])
            ->with('finance_status', sprintf('Facture %s émise : %s.', $invoice->number, Money::format($invoice->total)));
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $invoice->load(['lines', 'payments.method', 'payments.session.register', 'insurer', 'settlements', 'rejections']);

        return view('finance::invoices.show', [
            'invoice' => $invoice,
            // Où encaisser : les sessions ouvertes du caissier connecté.
            'openSessions' => CashSession::query()->open()->with('register')
                ->where('cashier_id', Actor::id($this->user($request)))
                ->orderBy('id')->get(),
        ]);
    }

    public function cancel(CancelMovementRequest $request, Invoice $invoice, CancelInvoice $action): RedirectResponse
    {
        $invoice = $action->handle($invoice, (string) $request->validated('reason'), $this->user($request));

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('finance_status', "Facture {$invoice->number} annulée.");
    }
}
