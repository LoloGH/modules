<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une vente peut donner plusieurs factures : une par organisme.
 *
 * Une ordonnance n'est pas couverte d'un bloc. L'assurance porte
 * l'amoxicilline, une aide sociale porte l'antipaludique, et le reste est a
 * la charge du patient. Mettre ces trois cas sur une seule piece etait
 * impossible : une facture porte un assureur, un statut de creance, un
 * montant regle et un montant rejete. Deux payeurs sur la meme piece, et le
 * suivi des creances ne veut plus rien dire.
 *
 * Les lignes sont donc groupees par organisme, et chaque groupe donne sa
 * facture — ce que fait aussi un hopital qui reclame a deux payeurs : chacun
 * recoit la sienne.
 *
 * L'unicite change en consequence : elle portait sur la piece d'origine, elle
 * porte desormais sur le couple piece + organisme. Une meme vente ne peut
 * toujours pas facturer deux fois le meme payeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropUnique('finance_invoices_source_unique');
            $table->unique(['source', 'source_reference', 'insurer_id'], 'finance_invoices_source_insurer_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropUnique('finance_invoices_source_insurer_unique');
            $table->unique(['source', 'source_reference'], 'finance_invoices_source_unique');
        });
    }
};
