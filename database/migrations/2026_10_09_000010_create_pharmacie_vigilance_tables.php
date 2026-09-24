<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rappels de lots et pharmacovigilance : ce qui se passe quand un produit
 * délivré s'avère suspect.
 *
 * Un rappel part d'un lot et remonte jusqu'aux patients : c'est la raison
 * d'être de `pharmacie_dispensation_batches`, qui garde depuis le premier
 * jour quel lot est parti dans quelle main. La liste des patients est figée à
 * l'ouverture du rappel, puis suivie ligne à ligne, car « prévenir les
 * patients » ne veut rien dire tant qu'on ne sait pas lequel reste à joindre.
 *
 * Un signalement d'effet indésirable relie le patient, le médicament, le lot
 * et la dispensation. Il ne se corrige pas en silence : il s'ouvre, se
 * transmet, se clôt avec une conclusion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_recalls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('number', 32)->unique();

            $table->foreignId('batch_id')->constrained('pharmacie_batches')->restrictOnDelete();

            // D'où vient l'alerte : le fabricant, l'autorité sanitaire, ou
            // la pharmacie elle-même (un lot douteux à la vue).
            $table->string('origin', 16)->default('interne');
            $table->string('reference', 64)->nullable();

            // Jusqu'où il faut aller : retirer du stock, ou rappeler les
            // patients déjà servis. Le second n'est pas le premier.
            $table->string('level', 24)->default('retrait_stock');
            $table->text('reason');

            // Ce qu'il restait en stock au moment du rappel : figé, car il
            // va bouger ensuite (destruction, retour fournisseur).
            $table->unsignedInteger('quantity_blocked')->default(0);

            $table->string('status', 16)->default('open');
            $table->string('opened_by_id', 64)->nullable();
            $table->string('opened_by_name')->nullable();
            $table->timestamp('opened_at')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_name')->nullable();
            $table->text('closing_note')->nullable();

            $table->timestamps();

            $table->index(['facility_id', 'status', 'created_at']);
        });

        // Un patient servi du lot rappelé. Figé à l'ouverture, pour que la
        // liste à joindre ne bouge pas sous les pieds de celui qui appelle.
        Schema::create('pharmacie_recall_patients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recall_id')->constrained('pharmacie_recalls')->cascadeOnDelete();
            $table->foreignId('dispensation_id')->nullable()->constrained('pharmacie_dispensations')->nullOnDelete();

            $table->string('patient_id', 64)->nullable()->index();
            $table->string('patient_name')->nullable();
            $table->string('product_label');
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamp('dispensed_at')->nullable();

            $table->boolean('contacted')->default(false);
            $table->timestamp('contacted_at')->nullable();
            $table->string('contacted_by_name')->nullable();
            $table->text('contact_note')->nullable();

            $table->timestamps();
        });

        Schema::create('pharmacie_adverse_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->string('number', 32)->unique();

            $table->string('patient_id', 64)->nullable()->index();
            $table->string('patient_name')->nullable();

            $table->foreignId('product_id')->nullable()->constrained('pharmacie_products')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('pharmacie_batches')->nullOnDelete();
            $table->foreignId('dispensation_id')->nullable()->constrained('pharmacie_dispensations')->nullOnDelete();

            $table->text('description');
            $table->string('severity', 16)->default('modere');
            $table->date('started_on')->nullable();
            $table->string('outcome', 24)->default('inconnu');
            $table->text('action_taken')->nullable();

            $table->string('status', 16)->default('nouveau');
            $table->string('reported_by_id', 64)->nullable();
            $table->string('reported_by_name')->nullable();
            $table->timestamp('reported_at')->nullable();

            $table->timestamp('transmitted_at')->nullable();
            $table->string('transmitted_to')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('conclusion')->nullable();

            $table->timestamps();

            $table->index(['facility_id', 'status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_adverse_events');
        Schema::dropIfExists('pharmacie_recall_patients');
        Schema::dropIfExists('pharmacie_recalls');
    }
};
