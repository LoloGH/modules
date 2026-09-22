<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Factures : ce que le patient doit, ligne par ligne, et ce qu'il en a réglé.
 *
 * `paid` et `status` sont tenus à jour à chaque encaissement ou annulation
 * d'encaissement rattaché (`finance_payments.invoice_id`), sous verrou : la
 * liste se lit sans recalcul. Une facture ne se supprime pas, elle s'annule
 * (motif, auteur).
 *
 * Le patient est un repère de l'hôte (identifiant et nom copiés) : le module
 * ne possède pas la table des patients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('patient_id', 64)->nullable()->index();
            $table->string('patient_name')->nullable();
            $table->string('status', 16)->default('unpaid');
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('paid')->default(0);
            $table->string('note')->nullable();
            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_id', 64)->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('finance_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->restrictOnDelete();
            $table->foreignId('act_id')->nullable()->constrained('finance_acts')->restrictOnDelete();
            $table->string('label');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_invoice_lines');
        Schema::dropIfExists('finance_invoices');
    }
};
