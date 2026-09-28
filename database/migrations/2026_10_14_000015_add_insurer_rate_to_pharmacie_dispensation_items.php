<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le taux pris en charge, ligne par ligne.
 *
 * Une prise en charge ne porte pas sur une dispensation entiere : un
 * organisme couvre l'amoxicilline a 80 % et ne couvre pas le sirop contre la
 * toux. Un taux unique pour toute la piece obligeait a choisir entre trop
 * couvrir et pas assez, et la caisse se retrouvait avec une creance que
 * l'organisme refuserait.
 *
 * Chaque ligne porte donc le sien, lu dans les regles du produit au moment de
 * la preparation. Zero veut dire « a la charge du patient », et c'est le cas
 * de toutes les lignes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacie_dispensation_items', function (Blueprint $table): void {
            $table->unsignedTinyInteger('insurer_rate')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacie_dispensation_items', function (Blueprint $table): void {
            $table->dropColumn('insurer_rate');
        });
    }
};
