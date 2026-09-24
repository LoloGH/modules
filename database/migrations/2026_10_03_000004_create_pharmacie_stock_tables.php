<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le stock : emplacements, lots, quantités et grand livre des mouvements.
 *
 * Le principe tient en une phrase : **le mouvement fait foi**. Les quantités
 * de `pharmacie_stocks` sont une commodité de lecture, recalculables à tout
 * moment des écritures de `pharmacie_stock_movements`, et modifiées seulement
 * sous verrou. Rien ne s'efface : une erreur s'annule par une écriture
 * inverse, jamais en effaçant.
 *
 * Un lot épuisé reste : sa traçabilité, qui l'a reçu, qui l'a servi, à qui,
 * survit à sa dernière unité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('code', 32);
            $table->string('name');
            // Pharmacie centrale, réserve, comptoir, service de soins…
            $table->string('kind', 24)->default('pharmacy');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['facility_id', 'code']);
        });

        Schema::create('pharmacie_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->foreignId('product_id')->constrained('pharmacie_products')->restrictOnDelete();

            $table->string('number', 64);
            // Le fournisseur arrive avec les réceptions : lien mou d'ici là.
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->string('supplier_name')->nullable();

            $table->date('received_on')->nullable();
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->unsignedBigInteger('purchase_price')->nullable();
            $table->unsignedBigInteger('sale_price')->nullable();

            // actif, bloqué (rappel, suspicion), détruit. « Épuisé » et
            // « périmé » se lisent des quantités et de la date : on ne les
            // écrit pas, pour qu'ils ne puissent pas mentir.
            $table->string('status', 16)->default('active');
            $table->text('block_reason')->nullable();
            $table->timestamps();

            $table->unique(['facility_id', 'product_id', 'number']);
            $table->index(['facility_id', 'expires_on']);
        });

        // Ce qu'il y a, lot par lot et emplacement par emplacement.
        Schema::create('pharmacie_stocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->foreignId('product_id')->constrained('pharmacie_products')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('pharmacie_batches')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('pharmacie_locations')->restrictOnDelete();

            // Physique = ce qui est sur l'étagère. Réservé = promis à une
            // préparation ou à un transfert. Disponible = la différence.
            $table->unsignedBigInteger('quantity')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->timestamps();

            $table->unique(['batch_id', 'location_id']);
            $table->index(['facility_id', 'product_id']);
        });

        // Le grand livre : une ligne par fait, jamais modifiée.
        Schema::create('pharmacie_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->foreignId('product_id')->constrained('pharmacie_products')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('pharmacie_batches')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('pharmacie_locations')->restrictOnDelete();

            // reception, dispensation, transfert_sortie, transfert_entree,
            // ajustement, perte, destruction, retour, annulation.
            $table->string('kind', 24);
            // Signée : positive pour une entrée, négative pour une sortie.
            $table->bigInteger('quantity');
            $table->unsignedBigInteger('quantity_after')->default(0);

            $table->text('reason')->nullable();
            // La pièce d'où vient le mouvement (réception, dispensation…).
            $table->string('document_type', 64)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_number', 32)->nullable();

            $table->string('actor_id', 64)->nullable();
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['facility_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
            $table->index(['document_type', 'document_id']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_stock_movements');
        Schema::dropIfExists('pharmacie_stocks');
        Schema::dropIfExists('pharmacie_batches');
        Schema::dropIfExists('pharmacie_locations');
    }
};
