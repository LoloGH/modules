<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le motif d'un encaissement : l'acte du catalogue qu'on encaisse.
 *
 * Jusqu'ici la raison d'un encaissement n'était qu'un libellé libre, ce qui
 * ne permettait pas de répondre à « combien rapportent les consultations,
 * le laboratoire, l'imagerie ». Le rattachement à `finance_acts` donne cette
 * réponse, et par ricochet au centre analytique de l'acte.
 *
 * Nullable : tout encaissement ne correspond pas à un acte du catalogue (une
 * avance, un reliquat), et les encaissements déjà enregistrés n'en ont pas.
 * `restrictOnDelete` comme partout : on ne cascade pas des données
 * financières, un acte ne se supprime pas, il se désactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table): void {
            $table->foreignId('act_id')->nullable()->after('payment_method_id')
                ->constrained('finance_acts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('act_id');
        });
    }
};
