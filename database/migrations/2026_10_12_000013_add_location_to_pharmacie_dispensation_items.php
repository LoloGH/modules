<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D'ou part chaque ligne servie.
 *
 * Une dispensation se fait depuis un emplacement, celui du comptoir. Mais
 * tout n'y est pas : l'amoxicilline est au comptoir, le vaccin dans la
 * chaine du froid, et le reste a la pharmacie centrale. Annoncer une rupture
 * parce que le produit dort dans la piece d'a cote est faux, et faire
 * ressaisir toute la dispensation a un autre emplacement l'est tout autant.
 *
 * Chaque ligne peut donc nommer l'emplacement d'ou elle est prise. Nulle,
 * elle suit celui de la dispensation : c'est le cas de toutes celles qui
 * existent deja, et du cas ordinaire ou tout vient du comptoir.
 *
 * Le grand livre, lui, ne change pas : le mouvement de sortie porte deja son
 * emplacement, et il dira desormais la verite pour ces lignes-la.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacie_dispensation_items', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('product_id')
                ->constrained('pharmacie_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pharmacie_dispensation_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
