<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les transferts entre emplacements : pharmacie centrale → urgences,
 * réserve → comptoir.
 *
 * Un transfert a un cycle, et chaque étape est une décision de quelqu'un :
 * **demandé → validé → sorti → reçu**. Entre la sortie et la réception, les
 * unités ne sont nulle part — elles sont **en transit**, et le système doit
 * pouvoir le dire : c'est là que le stock se perd, dans les pharmacies qui ne
 * le suivent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('number', 32)->unique();

            $table->foreignId('from_location_id')->constrained('pharmacie_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('pharmacie_locations')->restrictOnDelete();

            // demande → valide → envoye → recu ; ou refuse / annule.
            $table->string('status', 16)->default('requested');
            $table->text('reason')->nullable();

            $table->string('requested_by_id', 64)->nullable();
            $table->string('requested_by_name')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('sent_by_name')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('received_by_name')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('decision_reason')->nullable();

            $table->timestamps();

            $table->index(['facility_id', 'status', 'created_at']);
        });

        Schema::create('pharmacie_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('pharmacie_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pharmacie_products')->restrictOnDelete();
            // Le lot est choisi à l'envoi (FEFO), pas à la demande : on
            // demande un produit, on envoie un lot.
            $table->foreignId('batch_id')->nullable()->constrained('pharmacie_batches')->restrictOnDelete();

            $table->string('label');
            $table->unsignedInteger('quantity');
            // Ce qui est réellement arrivé : un écart de transport se voit.
            $table->unsignedInteger('received_quantity')->default(0);
            $table->text('gap_reason')->nullable();
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_transfer_lines');
        Schema::dropIfExists('pharmacie_transfers');
    }
};
