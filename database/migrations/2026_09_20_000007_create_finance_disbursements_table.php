<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Décaissements (sorties de caisse). Même règle que les encaissements :
 * jamais supprimés, annulés avec motif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('cash_session_id')->constrained('finance_cash_sessions')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('finance_payment_methods')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->text('reason');
            $table->string('beneficiary')->nullable();
            $table->string('reference')->nullable();
            $table->string('status', 16)->default('valid');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_id', 64)->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['cash_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_disbursements');
    }
};
