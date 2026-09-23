<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit de la pharmacie : on n'y écrit qu'en ajoutant.
 *
 * Aucune clé étrangère vers l'utilisateur ni vers l'objet audité : le
 * journal doit survivre à la suppression d'un compte et rester lisible sans
 * les tables métier. L'identité de l'utilisateur y est donc copiée (nom) et
 * les identifiants sont des chaînes, pour rester valables quel que soit le
 * type de clé de l'hôte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 64);
            $table->string('user_id', 64)->nullable();
            $table->string('user_name')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('event');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_audit_logs');
    }
};
