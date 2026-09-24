<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Keneya\Pharmacie\Http\Controllers\AlertController;
use Keneya\Pharmacie\Http\Controllers\CategoryController;
use Keneya\Pharmacie\Http\Controllers\DispensingController;
use Keneya\Pharmacie\Http\Controllers\HomeController;
use Keneya\Pharmacie\Http\Controllers\InventoryController;
use Keneya\Pharmacie\Http\Controllers\LocationController;
use Keneya\Pharmacie\Http\Controllers\PrescriptionController;
use Keneya\Pharmacie\Http\Controllers\ProductController;
use Keneya\Pharmacie\Http\Controllers\QueueController;
use Keneya\Pharmacie\Http\Controllers\StockController;
use Keneya\Pharmacie\Http\Controllers\SupplyController;
use Keneya\Pharmacie\Http\Controllers\TransferController;

/*
| Les droits se contrôlent ici, route par route (middleware `can:`), et les
| règles métier dans les actions. Toujours des contrôleurs, jamais de
| closures : `route:cache` échouerait chez l'hôte.
*/

Route::get('/', HomeController::class)->name('home');

// La file d'attente de la pharmacie, fournie par l'application hôte : on la
// consulte avec le droit de la voir, on appelle avec celui d'appeler.
Route::get('file', [QueueController::class, 'index'])
    ->middleware('can:pharmacie.queue.view')->name('queue.index');

Route::post('file/appeler', [QueueController::class, 'callNext'])
    ->middleware('can:pharmacie.queue.call')->name('queue.call');

// Catalogue : produits et categories. Consulter et gerer sont deux droits
// distincts ; fixer un prix en est un troisieme.
Route::middleware('can:pharmacie.products.view')->group(function (): void {
    Route::get('catalogue/produits', [ProductController::class, 'index'])->name('catalog.products.index');
    Route::get('catalogue/produits/{product}', [ProductController::class, 'show'])->name('catalog.products.show');
    Route::get('catalogue/categories', [CategoryController::class, 'index'])->name('catalog.categories.index');
});

// Alertes : chacune est deja filtree par le droit d'agir dessus.
Route::get('alertes', [AlertController::class, 'index'])->name('alerts.index');

// Ordonnances du dossier medical : on les lit, on ne les duplique pas.
Route::middleware('can:pharmacie.dispensing.view')->group(function (): void {
    Route::get('ordonnances', [PrescriptionController::class, 'index'])->name('prescriptions.index');
    Route::get('ordonnances/{reference}', [PrescriptionController::class, 'show'])->name('prescriptions.show');
});

// Dispensation : le comptoir. Lire ce qui a ete delivre, delivrer, annuler :
// trois droits distincts.
Route::middleware('can:pharmacie.dispensing.view')->group(function (): void {
    Route::get('dispensations', [DispensingController::class, 'index'])->name('dispensing.index');
    Route::get('dispensations/{dispensation}', [DispensingController::class, 'show'])
        ->whereNumber('dispensation')->name('dispensing.show');
    Route::get('dispensations/{dispensation}/bon', [DispensingController::class, 'print'])
        ->whereNumber('dispensation')->name('dispensing.print');
});

Route::middleware('can:pharmacie.dispensing.create')->group(function (): void {
    Route::get('comptoir', [DispensingController::class, 'create'])->name('dispensing.create');
    Route::post('dispensations', [DispensingController::class, 'store'])->name('dispensing.store');
});

// Ce qui doit etre paye part a la caisse : la pharmacie ne tient pas de
// tiroir.
Route::post('dispensations/{dispensation}/facturation', [DispensingController::class, 'bill'])
    ->middleware('can:pharmacie.dispensing.create')->whereNumber('dispensation')->name('dispensing.bill');

Route::post('dispensations/{dispensation}/annulation', [DispensingController::class, 'cancel'])
    ->middleware('can:pharmacie.dispensing.cancel')->whereNumber('dispensation')->name('dispensing.cancel');

// Approvisionnement : fournisseurs, commandes, receptions. La reception
// est le seul chemin par lequel un lot nait.
Route::middleware('can:pharmacie.stock.view')->group(function (): void {
    Route::get('fournisseurs', [SupplyController::class, 'suppliers'])->name('supply.suppliers.index');
    Route::get('fournisseurs/{supplier}', [SupplyController::class, 'supplier'])->name('supply.suppliers.show');
    Route::get('commandes', [SupplyController::class, 'orders'])->name('supply.orders.index');
    Route::get('commandes/{order}', [SupplyController::class, 'order'])->name('supply.orders.show');
    Route::get('receptions', [SupplyController::class, 'receptions'])->name('supply.receptions.index');
    Route::get('receptions/{reception}', [SupplyController::class, 'reception'])->name('supply.receptions.show');
});

Route::middleware('can:pharmacie.stock.receive')->group(function (): void {
    Route::post('fournisseurs', [SupplyController::class, 'storeSupplier'])->name('supply.suppliers.store');
    Route::post('fournisseurs/{supplier}/basculer', [SupplyController::class, 'toggleSupplier'])->name('supply.suppliers.toggle');
    Route::post('commandes', [SupplyController::class, 'storeOrder'])->name('supply.orders.store');
    Route::post('commandes/{order}/envoi', [SupplyController::class, 'sendOrder'])->name('supply.orders.send');
    Route::post('commandes/{order}/annulation', [SupplyController::class, 'cancelOrder'])->name('supply.orders.cancel');
    Route::post('receptions', [SupplyController::class, 'storeReception'])->name('supply.receptions.store');
});

// Stock : lots, emplacements, peremptions et grand livre des mouvements.
Route::middleware('can:pharmacie.stock.view')->group(function (): void {
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('stock/peremptions', [StockController::class, 'expiring'])->name('stock.expiring');
    Route::get('stock/emplacements', [LocationController::class, 'index'])->name('stock.locations.index');
    Route::get('stock/produits/{product}', [StockController::class, 'product'])->name('stock.products.show');
    Route::get('stock/lots/{batch}', [StockController::class, 'batch'])->name('stock.batches.show');
});

// Inventaires, pertes et destructions : compter n'est pas valider.
Route::middleware('can:pharmacie.stock.view')->group(function (): void {
    Route::get('inventaires', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventaires/{inventory}', [InventoryController::class, 'show'])->name('inventory.show');
    Route::get('pertes', [InventoryController::class, 'losses'])->name('inventory.losses');
});

Route::middleware('can:pharmacie.inventory.count')->group(function (): void {
    Route::post('inventaires', [InventoryController::class, 'open'])->name('inventory.open');
    Route::post('inventaires/{inventory}/comptage', [InventoryController::class, 'count'])->name('inventory.count');
});

Route::middleware('can:pharmacie.inventory.validate')->group(function (): void {
    Route::post('inventaires/{inventory}/validation', [InventoryController::class, 'validateInventory'])->name('inventory.validate');
    Route::post('inventaires/{inventory}/abandon', [InventoryController::class, 'cancel'])->name('inventory.cancel');
});

Route::post('pertes', [InventoryController::class, 'storeLoss'])
    ->middleware('can:pharmacie.stock.adjust')->name('inventory.losses.store');

// Transferts entre emplacements : demande, validation, envoi, reception.
Route::middleware('can:pharmacie.stock.view')->group(function (): void {
    Route::get('transferts', [TransferController::class, 'index'])->name('transfers.index');
    Route::get('transferts/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
});

Route::middleware('can:pharmacie.stock.adjust')->group(function (): void {
    Route::post('transferts', [TransferController::class, 'store'])->name('transfers.store');
    Route::post('transferts/{transfer}/decision', [TransferController::class, 'decide'])->name('transfers.decide');
    Route::post('transferts/{transfer}/envoi', [TransferController::class, 'send'])->name('transfers.send');
    Route::post('transferts/{transfer}/reception', [TransferController::class, 'receive'])->name('transfers.receive');
});

// Corriger, bloquer, ranger : un droit distinct de celui de lire.
Route::middleware('can:pharmacie.stock.adjust')->group(function (): void {
    Route::post('stock/ajustements', [StockController::class, 'adjust'])->name('stock.adjust');
    Route::post('stock/lots/{batch}/blocage', [StockController::class, 'toggleBatch'])->name('stock.batches.toggle');
    Route::post('stock/emplacements', [LocationController::class, 'store'])->name('stock.locations.store');
    Route::post('stock/emplacements/{location}/basculer', [LocationController::class, 'toggle'])->name('stock.locations.toggle');
});

Route::middleware('can:pharmacie.products.manage')->group(function (): void {
    Route::post('catalogue/produits', [ProductController::class, 'store'])->name('catalog.products.store');
    Route::post('catalogue/produits/{product}', [ProductController::class, 'update'])->name('catalog.products.update');
    Route::post('catalogue/produits/{product}/basculer', [ProductController::class, 'toggle'])->name('catalog.products.toggle');
    Route::post('catalogue/categories', [CategoryController::class, 'store'])->name('catalog.categories.store');
    Route::post('catalogue/categories/{category}/basculer', [CategoryController::class, 'toggle'])->name('catalog.categories.toggle');
});
