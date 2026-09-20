<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifs d'un acte. Un acte peut en avoir plusieurs selon le contexte
 * (`kind` : standard, conventionné, par assureur plus tard) mais un seul
 * ACTIF par contexte à un instant donné.
 *
 * Une ligne ne se modifie pas et ne se supprime pas : changer un tarif crée
 * une nouvelle ligne active et désactive la précédente, pour que l'historique
 * financier reste lisible.
 *
 * `amount` est un entier : le franc CFA n'a pas de décimale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_tariffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('act_id')->constrained('finance_acts')->restrictOnDelete();
            $table->string('kind', 32)->default('standard');
            $table->string('label')->nullable();
            $table->unsignedBigInteger('amount');
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['act_id', 'kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_tariffs');
    }
};
