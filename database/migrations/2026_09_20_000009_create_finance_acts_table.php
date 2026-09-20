<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actes et prestations facturables. Le catalogue est indépendant des
 * factures : un tarif évolue sans réécrire l'historique.
 *
 * Un acte se désactive, il ne se supprime pas (une facture passée s'y
 * réfère).
 *
 * `dme_service_id` est un lien mou vers `dme_services` de l'hôte : aucune
 * clé étrangère, seulement un index. Le module ne dépend pas de DME, et le
 * champ ne sert qu'aux rapprochements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_acts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('analytic_center_id')->nullable()->constrained('finance_analytic_centers')->restrictOnDelete();
            $table->unsignedBigInteger('dme_service_id')->nullable()->index();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_acts');
    }
};
