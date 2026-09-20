<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les caisses de l'établissement (ex. Caisse Ticket, Caisse Services).
 * Une caisse se désactive, elle ne se supprime pas : les sessions passées
 * s'y réfèrent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            // Réservé au multi-établissements : nul tant qu'il n'y en a qu'un.
            $table->unsignedBigInteger('facility_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_registers');
    }
};
