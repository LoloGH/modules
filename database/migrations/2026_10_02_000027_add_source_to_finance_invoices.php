<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D'où vient une facture, quand elle ne vient pas du guichet.
 *
 * Un autre module de l'établissement peut avoir déjà vendu quelque chose et
 * demander à Finance de le faire payer : la pharmacie délivre des
 * médicaments, les facture au prix de son propre catalogue, et le patient
 * règle à la caisse. Ces deux colonnes disent de quel module vient la pièce
 * et sous quelle référence elle y est connue.
 *
 * L'unicité du couple est le garde-fou contre la double facturation : une
 * même dispensation ne peut pas donner deux factures, même si le module
 * l'envoie deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->string('source', 32)->nullable()->after('patient_name');
            $table->string('source_reference', 64)->nullable()->after('source');

            $table->unique(['source', 'source_reference'], 'finance_invoices_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropUnique('finance_invoices_source_unique');
            $table->dropColumn(['source', 'source_reference']);
        });
    }
};
