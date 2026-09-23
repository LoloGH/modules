<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteurs des identifiants métier (FAC-2026-000001…).
 *
 * Une ligne par (clé, année) : la numérotation repart à 1 chaque année.
 * La ligne est verrouillée à chaque attribution, pour que deux caissiers
 * qui encaissent en même temps n'obtiennent jamais le même numéro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('sequence_key', 64);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['sequence_key', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_sequences');
    }
};
