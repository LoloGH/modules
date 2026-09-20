<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Keneya\FinanceCaisse\Http\Controllers\ActController;
use Keneya\FinanceCaisse\Http\Controllers\AnalyticCenterController;
use Keneya\FinanceCaisse\Http\Controllers\CashDeskController;
use Keneya\FinanceCaisse\Http\Controllers\HomeController;
use Keneya\FinanceCaisse\Http\Controllers\MovementController;
use Keneya\FinanceCaisse\Http\Controllers\RegisterController;
use Keneya\FinanceCaisse\Http\Controllers\ReviewController;
use Keneya\FinanceCaisse\Http\Controllers\TariffController;

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
});

Route::post('caisse/sessions', [CashDeskController::class, 'open'])
    ->middleware('can:finance.sessions.open')->name('cash.sessions.open');

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
});

// Fixer ou changer un prix n'est pas gérer le catalogue : droit distinct.
Route::post('catalogue/actes/{act}/tarifs', [TariffController::class, 'store'])
    ->middleware('can:finance.tariffs.manage')->name('catalog.tariffs.store');
