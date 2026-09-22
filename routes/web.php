<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Keneya\FinanceCaisse\Http\Controllers\ActController;
use Keneya\FinanceCaisse\Http\Controllers\AnalyticCenterController;
use Keneya\FinanceCaisse\Http\Controllers\CashDeskController;
use Keneya\FinanceCaisse\Http\Controllers\CashierAccessController;
use Keneya\FinanceCaisse\Http\Controllers\CashQueueController;
use Keneya\FinanceCaisse\Http\Controllers\HomeController;
use Keneya\FinanceCaisse\Http\Controllers\InsuranceController;
use Keneya\FinanceCaisse\Http\Controllers\InvoiceController;
use Keneya\FinanceCaisse\Http\Controllers\LedgerController;
use Keneya\FinanceCaisse\Http\Controllers\MovementController;
use Keneya\FinanceCaisse\Http\Controllers\PrintController;
use Keneya\FinanceCaisse\Http\Controllers\ReceivableController;
use Keneya\FinanceCaisse\Http\Controllers\RegisterController;
use Keneya\FinanceCaisse\Http\Controllers\ReportController;
use Keneya\FinanceCaisse\Http\Controllers\ReviewController;
use Keneya\FinanceCaisse\Http\Controllers\TariffController;
use Keneya\FinanceCaisse\Http\Controllers\UserPermissionController;

/*
| Les droits se contrôlent ici, route par route (middleware `can:`), et les
| règles métier dans les actions. Toujours des contrôleurs, jamais de
| closures : `route:cache` échouerait chez l'hôte.
*/

Route::get('/', HomeController::class)->name('home');

// Bureau du caissier
Route::middleware('can:finance.sessions.view')->group(function (): void {
    Route::get('caisse', [CashDeskController::class, 'index'])->name('cash.index');
    Route::get('caisse/sessions/{session}', [CashDeskController::class, 'show'])->name('cash.sessions.show');

    // Reçus imprimables : ses propres mouvements, ou tous pour le contrôle.
    Route::get('caisse/encaissements/{payment}/recu', [PrintController::class, 'payment'])->name('cash.payments.receipt');
    Route::get('caisse/decaissements/{disbursement}/recu', [PrintController::class, 'disbursement'])->name('cash.disbursements.receipt');
});

Route::post('caisse/sessions', [CashDeskController::class, 'open'])
    ->middleware('can:finance.sessions.open')->name('cash.sessions.open');

// Plusieurs caisses d'un coup, chacune avec son fonds initial.
Route::post('caisse/sessions/multiples', [CashDeskController::class, 'openMany'])
    ->middleware('can:finance.sessions.open')->name('cash.sessions.open-many');

// File d'attente des caisses, fournie par l'hôte : on la consulte avec le
// droit de voir ses sessions, on appelle le suivant avec celui d'encaisser.
Route::get('file', [CashQueueController::class, 'index'])
    ->middleware('can:finance.sessions.view')->name('queue.index');

Route::post('file/appeler', [CashQueueController::class, 'callNext'])
    ->middleware('can:finance.payments.create')->name('queue.call');

Route::post('caisse/sessions/{session}/cloture', [CashDeskController::class, 'close'])
    ->middleware('can:finance.sessions.close')->name('cash.sessions.close');

Route::post('caisse/sessions/{session}/encaissements', [MovementController::class, 'storePayment'])
    ->middleware('can:finance.payments.create')->name('cash.payments.store');

Route::post('caisse/sessions/{session}/decaissements', [MovementController::class, 'storeDisbursement'])
    ->middleware('can:finance.disbursements.create')->name('cash.disbursements.store');

Route::post('caisse/encaissements/{payment}/annulation', [MovementController::class, 'cancelPayment'])
    ->middleware('can:finance.payments.cancel')->name('cash.payments.cancel');

Route::post('caisse/decaissements/{disbursement}/annulation', [MovementController::class, 'cancelDisbursement'])
    ->middleware('can:finance.disbursements.cancel')->name('cash.disbursements.cancel');

// Factures. « nouvelle » avant {invoice}, que l'on restreint aux nombres.
Route::get('factures', [InvoiceController::class, 'index'])
    ->middleware('can:finance.invoices.view')->name('invoices.index');
Route::get('factures/nouvelle', [InvoiceController::class, 'create'])
    ->middleware('can:finance.invoices.create')->name('invoices.create');
Route::post('factures', [InvoiceController::class, 'store'])
    ->middleware('can:finance.invoices.create')->name('invoices.store');
Route::get('factures/{invoice}', [InvoiceController::class, 'show'])
    ->middleware('can:finance.invoices.view')->whereNumber('invoice')->name('invoices.show');
Route::get('factures/{invoice}/impression', [PrintController::class, 'invoice'])
    ->middleware('can:finance.invoices.view')->whereNumber('invoice')->name('invoices.print');
Route::post('factures/{invoice}/annulation', [InvoiceController::class, 'cancel'])
    ->middleware('can:finance.invoices.cancel')->whereNumber('invoice')->name('invoices.cancel');

// Paiements, recettes, dépenses : les écritures de caisse, en lecture. Un
// caissier n'y voit que ses propres sessions (voir LedgerController).
Route::middleware('can:finance.payments.view')->group(function (): void {
    Route::get('paiements', [LedgerController::class, 'payments'])->name('ledger.payments');
    Route::get('recettes', [LedgerController::class, 'revenue'])->name('ledger.revenue');
    Route::get('depenses', [LedgerController::class, 'expenses'])->name('ledger.expenses');
});

// Assurances : prises en charge et assureurs ; règlements et rejets par le
// contrôle.
Route::middleware('can:finance.insurance.view')->group(function (): void {
    Route::get('assurances', [InsuranceController::class, 'index'])->name('insurance.index');
    Route::get('assurances/assureurs/{insurer}', [InsuranceController::class, 'showInsurer'])->name('insurers.show');
});

Route::middleware('can:finance.insurance.manage')->group(function (): void {
    Route::post('assurances/assureurs', [InsuranceController::class, 'storeInsurer'])->name('insurers.store');
    Route::post('assurances/assureurs/{insurer}/basculer', [InsuranceController::class, 'toggleInsurer'])->name('insurers.toggle');
    Route::post('assurances/assureurs/{insurer}/couverture', [InsuranceController::class, 'updateCoverage'])->name('insurers.coverage');
    Route::post('factures/{invoice}/assurance/reglements', [InsuranceController::class, 'settle'])->whereNumber('invoice')->name('insurance.settle');
    Route::post('factures/{invoice}/assurance/rejets', [InsuranceController::class, 'reject'])->whereNumber('invoice')->name('insurance.reject');
});

// Créances : ce que patients et assureurs doivent encore (lecture).
Route::get('creances', [ReceivableController::class, 'index'])
    ->middleware('can:finance.receivables.view')->name('receivables.index');

// Rapports : pilotage de tout l'établissement, avec export CSV.
Route::middleware('can:finance.reports.view')->group(function (): void {
    Route::get('rapports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('rapports/export', [ReportController::class, 'export'])->name('reports.export');
});

// Contrôle
Route::middleware('can:finance.sessions.validate')->group(function (): void {
    Route::get('sessions', [ReviewController::class, 'index'])->name('review.index');
    Route::post('sessions/{session}/validation', [ReviewController::class, 'approve'])->name('review.approve');
});

// Administration des caisses
Route::middleware('can:finance.registers.manage')->group(function (): void {
    Route::get('caisses', [RegisterController::class, 'index'])->name('registers.index');
    Route::post('caisses', [RegisterController::class, 'store'])->name('registers.store');
    Route::post('caisses/{register}/basculer', [RegisterController::class, 'toggle'])->name('registers.toggle');
    Route::post('caisses/caissiers', [CashierAccessController::class, 'store'])->name('registers.access.store');
});

// Utilisateurs : les capacités de chacun dans le module, réglées ici sans
// toucher aux rôles de l'hôte.
Route::middleware('can:finance.roles.manage')->group(function (): void {
    Route::get('utilisateurs', [UserPermissionController::class, 'index'])->name('users.index');
    Route::post('utilisateurs/capacites', [UserPermissionController::class, 'store'])->name('users.permissions.store');
    Route::post('utilisateurs/capacites/reinitialiser', [UserPermissionController::class, 'reset'])->name('users.permissions.reset');
});

// Catalogue des actes et tarifs
Route::middleware('can:finance.catalog.view')->group(function (): void {
    Route::get('catalogue/centres', [AnalyticCenterController::class, 'index'])->name('catalog.centers.index');
    Route::get('catalogue/actes', [ActController::class, 'index'])->name('catalog.acts.index');
    Route::get('catalogue/actes/{act}', [ActController::class, 'show'])->name('catalog.acts.show');
});

Route::middleware('can:finance.catalog.manage')->group(function (): void {
    Route::post('catalogue/centres', [AnalyticCenterController::class, 'store'])->name('catalog.centers.store');
    Route::post('catalogue/centres/{center}/basculer', [AnalyticCenterController::class, 'toggle'])->name('catalog.centers.toggle');
    Route::post('catalogue/actes', [ActController::class, 'store'])->name('catalog.acts.store');
    Route::post('catalogue/actes/{act}/basculer', [ActController::class, 'toggle'])->name('catalog.acts.toggle');
    Route::post('catalogue/actes/{act}/ticket', [ActController::class, 'ticket'])->name('catalog.acts.ticket');
});

// Fixer ou changer un prix n'est pas gérer le catalogue : droit distinct.
Route::post('catalogue/actes/{act}/tarifs', [TariffController::class, 'store'])
    ->middleware('can:finance.tariffs.manage')->name('catalog.tariffs.store');
