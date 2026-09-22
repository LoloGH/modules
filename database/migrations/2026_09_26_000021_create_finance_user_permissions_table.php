<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les capacités qu'un utilisateur tient dans le module, réglées dans Finance
 * (écran « Utilisateurs »), indépendamment des rôles de l'application hôte.
 *
 * Une ligne par utilisateur : sa présence dit que ses droits sont réglés
 * ici ; sans ligne, il garde ceux que l'hôte lui donne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_user_permissions', function (Blueprint $table): void {
            $table->id();
            // Identifiant tel que Finance le copie (Support\Actor::id()) :
            // aucune clé étrangère, la table des utilisateurs est à l'hôte.
            $table->string('user_id', 64)->unique();
            $table->string('user_name')->nullable();
            $table->json('permissions');
            $table->string('updated_by_id', 64)->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_user_permissions');
    }
};
