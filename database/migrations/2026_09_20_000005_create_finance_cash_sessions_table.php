<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions de caisse : ouverte, clôturée par le caissier, validée par un
 * autre profil. Les montants sont des entiers (franc CFA, sans décimale).
 *
 * `expected_cash` et `variance` sont signés : un écart peut être négatif
 * (manque) ou positif (excédent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('cash_register_id')->constrained('finance_cash_registers')->restrictOnDelete();
            $table->string('cashier_id', 64);
            $table->string('cashier_name')->nullable();
            $table->string('status', 16)->default('open');
            $table->unsignedBigInteger('opening_float')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('counted_cash')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->text('variance_reason')->nullable();
            $table->json('totals')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('validator_id', 64)->nullable();
            $table->string('validator_name')->nullable();
            $table->text('validation_note')->nullable();
            $table->timestamps();

            $table->index(['cash_register_id', 'status']);
            $table->index(['cashier_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_sessions');
    }
};
