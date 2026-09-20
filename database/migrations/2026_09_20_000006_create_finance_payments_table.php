<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encaissements. Jamais supprimés : une erreur passe par une annulation
 * (statut, auteur, motif), qui les exclut des totaux sans les effacer.
 *
 * `invoice_id` est réservé aux factures : la clé étrangère sera ajoutée par
 * la migration qui créera la table des factures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('cash_session_id')->constrained('finance_cash_sessions')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('finance_payment_methods')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('reference')->nullable();
            $table->string('patient_id', 64)->nullable();
            $table->string('patient_name')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('status', 16)->default('valid');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_id', 64)->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['cash_session_id', 'status']);
            $table->index('patient_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payments');
    }
};
