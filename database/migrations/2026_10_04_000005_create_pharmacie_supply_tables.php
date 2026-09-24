<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'approvisionnement : fournisseurs, commandes et réceptions.
 *
 * Une réception n'est pas une saisie de stock : c'est un **contrôle**. On
 * compare ce qui était commandé à ce qui arrive, on relève les lots et leurs
 * péremptions, on signale les anomalies, et c'est seulement ensuite que les
 * unités entrent, par le grand livre.
 *
 * Une réception validée ne se réécrit pas : une erreur se corrige par un
 * ajustement de stock motivé, qui reste lisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            // Conditions commerciales : délai de paiement, de livraison…
            $table->unsignedInteger('payment_days')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['facility_id', 'code']);
        });

        Schema::create('pharmacie_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained('pharmacie_suppliers')->restrictOnDelete();

            // brouillon vers envoyée vers reçue (partiellement ou totalement) vers annulée
            $table->string('status', 16)->default('draft');
            $table->date('ordered_on')->nullable();
            $table->date('expected_on')->nullable();
            $table->unsignedBigInteger('total')->default(0);
            $table->text('notes')->nullable();

            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_name')->nullable();
            $table->string('sent_by_name')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'status', 'created_at']);
        });

        Schema::create('pharmacie_purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('pharmacie_purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pharmacie_products')->restrictOnDelete();
            $table->string('label');
            $table->unsignedInteger('quantity');
            // Ce qui est déjà arrivé : le reste est en attente.
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();

            $table->index('product_id');
        });

        Schema::create('pharmacie_receptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained('pharmacie_suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('pharmacie_purchase_orders')->nullOnDelete();
            $table->foreignId('location_id')->constrained('pharmacie_locations')->restrictOnDelete();

            $table->date('received_on');
            $table->string('delivery_note', 64)->nullable();
            $table->unsignedBigInteger('total')->default(0);
            // Ce que le réceptionnaire a relevé : casse, manquant, écart de
            // péremption. Une anomalie ne bloque pas l'entrée, elle la
            // documente.
            $table->text('anomalies')->nullable();
            $table->text('notes')->nullable();

            $table->string('received_by_id', 64)->nullable();
            $table->string('received_by_name')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'received_on']);
        });

        Schema::create('pharmacie_reception_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reception_id')->constrained('pharmacie_receptions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pharmacie_products')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('pharmacie_batches')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_reception_lines');
        Schema::dropIfExists('pharmacie_receptions');
        Schema::dropIfExists('pharmacie_purchase_order_items');
        Schema::dropIfExists('pharmacie_purchase_orders');
        Schema::dropIfExists('pharmacie_suppliers');
    }
};
