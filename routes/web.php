<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Keneya\Pharmacie\Http\Controllers\CategoryController;
use Keneya\Pharmacie\Http\Controllers\HomeController;
use Keneya\Pharmacie\Http\Controllers\ProductController;
use Keneya\Pharmacie\Http\Controllers\QueueController;

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

Route::middleware('can:pharmacie.products.manage')->group(function (): void {
    Route::post('catalogue/produits', [ProductController::class, 'store'])->name('catalog.products.store');
    Route::post('catalogue/produits/{product}', [ProductController::class, 'update'])->name('catalog.products.update');
    Route::post('catalogue/produits/{product}/basculer', [ProductController::class, 'toggle'])->name('catalog.products.toggle');
    Route::post('catalogue/categories', [CategoryController::class, 'store'])->name('catalog.categories.store');
    Route::post('catalogue/categories/{category}/basculer', [CategoryController::class, 'toggle'])->name('catalog.categories.toggle');
});
