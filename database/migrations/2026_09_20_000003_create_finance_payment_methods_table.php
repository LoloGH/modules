<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moyens de paiement, configurables : aucun n'est codé en dur dans la
 * logique de caisse. Un moyen se désactive, il ne se supprime pas (les
 * paiements passés continuent de s'y référer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('kind', 32);
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payment_methods');
    }
};
