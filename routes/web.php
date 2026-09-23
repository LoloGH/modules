<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Keneya\Pharmacie\Http\Controllers\HomeController;
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
