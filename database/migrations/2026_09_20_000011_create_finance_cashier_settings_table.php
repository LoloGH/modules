<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages propres à un caissier, qui surchargent le défaut de
 * l'établissement.
 *
 * Une seule ligne par caissier, repérée par `cashier_id` — la même chaîne
 * que celle copiée dans les sessions de caisse, valable quel que soit le type
 * de clé de l'hôte. Aucune clé étrangère : le module ne possède pas la table
 * des utilisateurs.
 *
 * `max_open_sessions` nul, ou ligne absente, signifie « applique le défaut de
 * `config('finance.cash.max_open_sessions_per_cashier')` ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cashier_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('cashier_id', 64)->unique();
            // Copié pour que l'écran d'administration reste lisible même si le
            // compte disparaît de l'hôte, comme `cashier_name` sur les sessions.
            $table->string('cashier_name')->nullable();
            $table->unsignedSmallInteger('max_open_sessions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cashier_settings');
    }
};
