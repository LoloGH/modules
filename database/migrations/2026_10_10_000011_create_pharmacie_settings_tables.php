<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que l'établissement règle lui-même, sans toucher au fichier de
 * configuration ni redéployer.
 *
 * Deux tables, deux questions distinctes :
 *
 *   - `pharmacie_settings` : les réglages du module — durées d'alerte,
 *     numérotation, règles des produits sous surveillance. Sans ligne, le
 *     fichier de configuration fait foi, et le module tourne comme avant.
 *   - `pharmacie_user_permissions` : ce que chacun peut faire ici. L'hôte
 *     décide qui ENTRE dans le module ; une fois entré, ses capacités
 *     peuvent se régler depuis la pharmacie, sans rien changer chez l'hôte.
 *
 * Aucune clé étrangère vers les utilisateurs : la table des comptes
 * appartient à l'application hôte, et un module ne contraint pas ses tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_settings', function (Blueprint $table): void {
            // La clé de configuration, sans le préfixe `pharmacie.` :
            // « stock.expiry_warning_days ».
            $table->string('key', 64)->primary();
            // Valeur encodée en JSON : un nombre, une chaîne ou un booléen.
            $table->text('value')->nullable();
            $table->string('updated_by_id', 64)->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();
        });

        Schema::create('pharmacie_user_permissions', function (Blueprint $table): void {
            $table->id();
            // L'identifiant tel que la pharmacie le copie dans ses écritures
            // (Support\Actor::id()) : le réglage vaut donc dès la première
            // venue de la personne, avant même qu'elle ait servi.
            $table->string('user_id', 64)->unique();
            $table->string('user_name')->nullable();
            $table->json('permissions');
            $table->string('updated_by_id', 64)->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_user_permissions');
        Schema::dropIfExists('pharmacie_settings');
    }
};
