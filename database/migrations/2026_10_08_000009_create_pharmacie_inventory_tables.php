<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventaires, pertes et destructions.
 *
 * Un inventaire compare le **théorique** au **compté**. L'écart n'est pas une
 * erreur à effacer : c'est une information. Il se justifie ligne par ligne,
 * se valide par quelqu'un d'autre que celui qui a compté, et ne corrige le
 * stock qu'à la validation, par des écritures d'ajustement qui restent au
 * grand livre.
 *
 * Une destruction, elle, est une décision : quoi, combien, pourquoi, et un
 * document qui l'atteste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_inventories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained('pharmacie_locations')->restrictOnDelete();

            // complet, partiel (une catégorie, un produit)
            $table->string('scope', 16)->default('full');
            $table->foreignId('category_id')->nullable()->constrained('pharmacie_categories')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pharmacie_products')->nullOnDelete();

            // ouvert (en cours de comptage) vers validé, ou abandonné
            $table->string('status', 16)->default('open');
            $table->text('notes')->nullable();

            $table->string('counted_by_id', 64)->nullable();
            $table->string('counted_by_name')->nullable();
            $table->string('validated_by_id', 64)->nullable();
            $table->string('validated_by_name')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['facility_id', 'status', 'created_at']);
        });

        Schema::create('pharmacie_inventory_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained('pharmacie_inventories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pharmacie_products')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('pharmacie_batches')->restrictOnDelete();

            $table->string('label');
            // Le théorique est figé à l'ouverture : ce que le système croyait
            // avoir au moment où l'on a commencé à compter.
            $table->unsignedInteger('expected_quantity')->default(0);
            $table->unsignedInteger('counted_quantity')->nullable();
            $table->integer('gap')->default(0);
            $table->text('gap_reason')->nullable();
            $table->timestamps();

            $table->index('batch_id');
        });

        Schema::create('pharmacie_losses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('number', 32)->unique();
            $table->foreignId('batch_id')->constrained('pharmacie_batches')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('pharmacie_locations')->restrictOnDelete();

            // perime, casse, vol, deterioration, autre
            $table->string('kind', 24)->default('expired');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('value')->default(0);
            $table->text('reason');
            // Vrai lorsque le produit est physiquement détruit (et non
            // seulement sorti du stock disponible).
            $table->boolean('destroyed')->default(false);
            $table->string('witness')->nullable();

            $table->string('recorded_by_id', 64)->nullable();
            $table->string('recorded_by_name')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_losses');
        Schema::dropIfExists('pharmacie_inventory_lines');
        Schema::dropIfExists('pharmacie_inventories');
    }
};
