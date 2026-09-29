<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quel organisme porte chaque ligne.
 *
 * Une ordonnance n'est pas couverte d'un bloc. L'assurance porte
 * l'antibiotique, une aide sociale porte l'antipaludique, et le sirop reste a
 * la charge du patient. Appliquer un seul organisme a toutes les lignes
 * obligeait a couvrir ce qui ne l'est pas, ou a ne rien couvrir.
 *
 * Chaque ligne nomme donc le sien, choisi au comptoir parmi ceux que la
 * caisse declare et qui couvrent ce produit. Le nom est recopie a cote de la
 * reference : si la caisse renomme l'organisme, la ligne servie reste lisible
 * telle qu'elle a ete servie.
 *
 * Nul veut dire « a la charge du patient », et c'est le cas de toutes les
 * lignes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacie_dispensation_items', function (Blueprint $table): void {
            $table->string('insurer_ref', 64)->nullable()->after('insurer_rate');
            $table->string('insurer_name')->nullable()->after('insurer_ref');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacie_dispensation_items', function (Blueprint $table): void {
            $table->dropColumn(['insurer_ref', 'insurer_name']);
        });
    }
};
