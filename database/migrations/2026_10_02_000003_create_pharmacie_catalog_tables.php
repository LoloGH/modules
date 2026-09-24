<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le catalogue pharmaceutique : ce que la pharmacie sait référencer.
 *
 * Un produit n'est pas seulement un nom : c'est une DCI, une forme, un dosage,
 * une voie d'administration et un conditionnement. Deux produits de même nom
 * commercial mais de dosage différent sont deux produits, les confondre, c'est
 * délivrer le mauvais.
 *
 * `facility_id` dès la première table : un établissement peut en compter
 * plusieurs (pharmacie centrale, pharmacies secondaires), et l'ajouter après
 * coup coûterait une réécriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['facility_id', 'code']);
        });

        Schema::create('pharmacie_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();

            // Identification
            $table->string('code', 32);
            $table->string('name');
            $table->string('dci')->nullable();
            $table->string('brand_name')->nullable();
            $table->string('laboratory')->nullable();
            $table->string('barcode', 64)->nullable();

            // Nature : un pansement ne se gère pas comme un antibiotique.
            $table->string('kind', 16)->default('medicine');
            $table->foreignId('category_id')->nullable()->constrained('pharmacie_categories')->nullOnDelete();
            $table->string('therapeutic_class')->nullable();

            // Présentation
            $table->string('form', 64)->nullable();
            $table->string('dosage', 64)->nullable();
            $table->string('route', 64)->nullable();
            $table->string('unit', 32)->default('unité');
            $table->string('packaging')->nullable();
            $table->boolean('is_generic')->default(false);

            // Gestion
            $table->unsignedInteger('min_threshold')->default(0);
            $table->unsignedInteger('max_threshold')->nullable();
            $table->unsignedBigInteger('sale_price')->nullable();
            $table->string('storage_conditions')->nullable();
            // Surveillance particulière (stupéfiants, produits sous contrôle) :
            // la règle reste configurable, le drapeau est porté ici.
            $table->boolean('is_controlled')->default(false);

            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['facility_id', 'code']);
            $table->index(['facility_id', 'is_active', 'name']);
            $table->index('barcode');
            $table->index('dci');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_products');
        Schema::dropIfExists('pharmacie_categories');
    }
};
