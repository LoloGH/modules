<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les paramètres financiers réglés depuis l'application, et non plus
 * seulement dans le fichier de configuration.
 *
 * Une ligne par paramètre changé : l'absence de ligne veut dire « comme le
 * fichier ». L'établissement règle ainsi ses délais, ses préfixes ou ses
 * catégories sans toucher au serveur, et revient au défaut en effaçant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settings', function (Blueprint $table): void {
            // La clé de configuration, sans le préfixe `finance.` :
            // « receivables.patient_due_days ».
            $table->string('key', 64)->primary();
            // Valeur encodée en JSON : un nombre, une chaîne ou une liste.
            $table->text('value')->nullable();
            $table->string('updated_by_id', 64)->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settings');
    }
};
