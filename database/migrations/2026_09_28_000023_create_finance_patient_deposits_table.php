<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les avances versées par les patients : de l'argent reçu d'avance, qui
 * n'est pas encore une recette.
 *
 * Une avance entre dans le tiroir comme un encaissement, mais elle reste due
 * au patient tant qu'elle n'a pas payé quelque chose : elle ne figure donc
 * pas dans les recettes. Ce qu'elle paie ensuite est un encaissement ordinaire
 * réglé par le moyen « Compte patient ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_patient_deposits', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('cash_session_id')->constrained('finance_cash_sessions')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('finance_payment_methods')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            // Identifiant du patient chez l'hôte : le module ne possède pas
            // le dossier, il copie l'identifiant et le nom.
            $table->string('patient_id', 64);
            $table->string('patient_name')->nullable();
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            $table->string('status', 16)->default('valid');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_id', 64)->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index(['cash_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_patient_deposits');
    }
};
