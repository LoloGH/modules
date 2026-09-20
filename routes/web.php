<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Keneya\FinanceCaisse\Http\Controllers\HomeController;

Route::get('/', HomeController::class)->name('home');
