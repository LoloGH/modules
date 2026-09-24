<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La dispensation : ce qui a été délivré, à qui, de quel lot, par qui.
 *
 * Trois quantités ne se confondent jamais : **prescrit**, **délivré**,
 * **restant à délivrer**. Une ordonnance dont tout n'est pas disponible se
 * sert partiellement, et le reliquat reste visible, il n'y a pas de honte à
 * manquer d'un médicament, il y en a à le cacher.
 *
 * Le reliquat vit ici, dans la pharmacie : c'est un fait de stock, pas un
 * fait médical. Le dossier du patient ne connaît que le statut global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_dispensations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('number', 32)->unique();

            // Le patient de l'hôte : on copie son identifiant et son nom,
            // jamais son dossier.
            $table->string('patient_id', 64)->nullable()->index();
            $table->string('patient_name')->nullable();

            // D'où vient la demande : la file de l'hôte, une ordonnance du
            // DME, ou un achat libre au comptoir.
            $table->string('source', 16)->default('counter');
            $table->string('queue_ref', 64)->nullable();
            $table->string('prescription_ref', 64)->nullable();

            $table->foreignId('location_id')->constrained('pharmacie_locations')->restrictOnDelete();

            // brouillon (en préparation) vers délivrée vers annulée
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('total')->default(0);
            // Ce qu'il reste à servir sur l'ordonnance, tous produits
            // confondus : zéro quand tout a été délivré.
            $table->unsignedInteger('outstanding')->default(0);

            $table->text('notes')->nullable();
            $table->string('dispensed_by_id', 64)->nullable();
            $table->string('dispensed_by_name')->nullable();
            $table->timestamp('dispensed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['facility_id', 'status', 'created_at']);
            $table->index('prescription_ref');
        });

        // Une ligne par produit demandé : ce qui est prescrit, ce qui a été
        // servi, ce qui manque.
        Schema::create('pharmacie_dispensation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispensation_id')->constrained('pharmacie_dispensations')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pharmacie_products')->restrictOnDelete();

            $table->string('label');
            $table->string('posology')->nullable();
            $table->unsignedInteger('prescribed_quantity')->default(0);
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('amount')->default(0);

            // Le produit réellement servi quand il diffère du prescrit :
            // une substitution se dit, elle ne se devine pas.
            $table->foreignId('substituted_for_id')->nullable()->constrained('pharmacie_products')->nullOnDelete();
            $table->text('substitution_reason')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('product_id');
        });

        // Le détail par lot : c'est ce qui permet, en cas de rappel, de
        // retrouver les patients servis.
        Schema::create('pharmacie_dispensation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispensation_item_id')->constrained('pharmacie_dispensation_items')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('pharmacie_batches')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // Vrai quand le pharmacien n'a pas suivi la proposition FEFO :
            // le motif est alors obligatoire.
            $table->boolean('overrode_fefo')->default(false);
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_dispensation_batches');
        Schema::dropIfExists('pharmacie_dispensation_items');
        Schema::dropIfExists('pharmacie_dispensations');
    }
};
