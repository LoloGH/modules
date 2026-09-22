<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remises et remboursements : deux gestes qui coûtent de l'argent à
 * l'établissement, et que celui qui les demande n'approuve jamais lui-même.
 *
 * La remise réduit ce que le patient doit sur une facture (colonne `discount`
 * sur la facture, une ligne par remise accordée). Le remboursement rend de
 * l'argent déjà encaissé : approuvé, il se paie à la caisse, et le
 * décaissement qui le paie lui reste attaché.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('discount')->default(0)->after('paid');
        });

        Schema::create('finance_discounts', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->text('reason');
            $table->string('status', 16)->default('requested');
            $table->string('requested_by_id', 64)->nullable();
            $table->string('requested_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_by_id', 64)->nullable();
            $table->string('decided_by_name')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('invoice_id');
        });

        Schema::create('finance_refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            // Ce qu'on rembourse : un encaissement précis, une facture, ou le
            // solde du compte du patient (aucun des deux).
            $table->foreignId('payment_id')->nullable()->constrained('finance_payments')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('finance_invoices')->restrictOnDelete();
            $table->string('source', 16)->default('payment');
            $table->string('patient_id', 64);
            $table->string('patient_name')->nullable();
            $table->unsignedBigInteger('amount');
            $table->text('reason');
            $table->string('status', 16)->default('requested');
            $table->string('requested_by_id', 64)->nullable();
            $table->string('requested_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_by_id', 64)->nullable();
            $table->string('decided_by_name')->nullable();
            $table->text('decision_reason')->nullable();
            // Le décaissement qui l'a payé, quand il a été payé.
            $table->foreignId('disbursement_id')->nullable()->constrained('finance_disbursements')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['patient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_refunds');
        Schema::dropIfExists('finance_discounts');

        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropColumn('discount');
        });
    }
};
